<?php

namespace App\Support\Activity;

use App\Models\ActivityLog;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Escribe la bitácora: quién hizo qué.
 *
 * Sólo registra acciones de una persona (petición autenticada). Lo que hace
 * el sistema por su cuenta —seeders, comandos programados, webhooks— no es
 * actividad de nadie y se omite. Corre en la misma transacción que el cambio:
 * si el guardado se revierte, el registro también.
 */
class ActivityLogger
{
    /** "Clase:id" del registro creado en esta petición → id de su renglón. */
    private static array $createdLogs = [];

    public static function log(
        string $module,
        string $event,
        string $description,
        ?Model $subject = null,
        ?string $subjectLabel = null,
        ?array $changes = null,
    ): ?ActivityLog {
        $user = Auth::user();
        if (!$user) {
            return null;
        }

        try {
            return ActivityLog::create([
                'company_id' => CurrentCompany::id() ?? $subject?->getAttribute('company_id'),
                'location_id' => CurrentLocation::id() ?? $subject?->getAttribute('location_id'),
                'user_id' => $user->getAuthIdentifier(),
                'user_name' => $user->name ?? null,
                'module' => $module,
                'event' => $event,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subjectLabel !== null ? mb_strimwidth($subjectLabel, 0, 250, '…') : null,
                'description' => mb_strimwidth($description, 0, 500, '…'),
                'changes' => $changes ?: null,
            ]);
        } catch (\Throwable $e) {
            // La bitácora nunca debe tumbar la operación que registra.
            Log::warning('No se pudo registrar la actividad', ['message' => $e->getMessage()]);

            return null;
        }
    }

    // ─── Eventos de modelo ──────────────────────────────────────────────────

    public static function created(Model $model): void
    {
        $config = ActivitySubjects::for($model);
        if (!$config || self::skipped($config, $model, 'created')) {
            return;
        }

        $log = self::log(
            $config['module'],
            'created',
            self::createdSentence($config, $model),
            $model,
            self::label($config, $model),
        );

        if ($log) {
            self::$createdLogs[self::key($model)] = $log->id;
        }
    }

    public static function updated(Model $model): void
    {
        $config = ActivitySubjects::for($model);
        if (!$config) {
            return;
        }

        // Se creó en esta misma petición (p. ej. la venta y luego sus totales):
        // no es una edición, se actualiza la frase de alta con el estado final.
        if ($model->wasRecentlyCreated && isset(self::$createdLogs[self::key($model)])) {
            ActivityLog::whereKey(self::$createdLogs[self::key($model)])->update([
                'description' => mb_strimwidth(self::createdSentence($config, $model), 0, 500, '…'),
                'subject_label' => self::label($config, $model),
            ]);

            return;
        }

        if (self::skipped($config, $model, 'updated')) {
            return;
        }

        // Un cambio de estado se cuenta con su verbo ("Recibió la compra…").
        if (isset($config['status']) && $model->wasChanged('status')) {
            $new = ActivityFormatter::value($model->getAttribute('status'));
            $sentence = is_callable($config['status'])
                ? ($config['status'])($model, $new)
                : (isset($config['status'][$new])
                    ? "{$config['status'][$new]} {$config['noun']} " . self::quotedLabel($config, $model)
                    : null);

            if ($sentence) {
                self::log($config['module'], 'status', $sentence, $model, self::label($config, $model), [
                    'status' => [ActivityFormatter::value($model->getOriginal('status')), $new],
                ]);

                return;
            }
        }

        $changes = self::changes($config, $model);
        if (!$changes) {
            return;
        }

        self::log($config['module'], 'updated', self::updatedSentence($config, $model, $changes), $model, self::label($config, $model), $changes);
    }

    public static function deleted(Model $model): void
    {
        $config = ActivitySubjects::for($model);
        if (!$config || self::skipped($config, $model, 'deleted')) {
            return;
        }

        $sentence = $model instanceof \App\Models\InventoryAdjustmentDetail
            ? 'Revirtió el ajuste de ' . self::label($config, $model)
            : "Eliminó {$config['noun']} " . self::quotedLabel($config, $model);

        self::log($config['module'], 'deleted', $sentence, $model, self::label($config, $model));
    }

    // ─── Frases ─────────────────────────────────────────────────────────────

    private static function createdSentence(array $config, Model $model): string
    {
        $created = $config['created'] ?? 'Creó';
        if (is_callable($created)) {
            return $created($model);
        }

        $sentence = "{$created} {$config['noun']} " . self::quotedLabel($config, $model);
        if (isset($config['amount']) && $model->getAttribute($config['amount']) !== null) {
            $sentence .= ' por ' . ActivityFormatter::money($model->getAttribute($config['amount']));
        }

        return $sentence;
    }

    /**
     * @param array<string, array{0: string, 1: string}> $changes
     */
    private static function updatedSentence(array $config, Model $model, array $changes): string
    {
        $subject = "{$config['noun']} " . self::quotedLabel($config, $model);

        if (array_keys($changes) === ['is_active']) {
            return ($changes['is_active'][1] === 'Sí' ? 'Activó ' : 'Desactivó ') . $subject;
        }
        if (array_keys($changes) === ['password']) {
            // "de el usuario" → "del usuario".
            return 'Cambió la contraseña ' . (str_starts_with($subject, 'el ') ? 'del ' . substr($subject, 3) : "de {$subject}");
        }

        $parts = [];
        foreach (array_slice($changes, 0, 3, true) as $field => [$old, $new]) {
            $label = ActivityFormatter::fieldLabel($field);
            $parts[] = $field === 'password' ? 'contraseña' : "{$label}: {$old} → {$new}";
        }
        $rest = count($changes) - count($parts);

        return "Editó {$subject}: " . implode('; ', $parts) . ($rest > 0 ? " y {$rest} cambio" . ($rest > 1 ? 's' : '') . ' más' : '');
    }

    /** @return array<string, array{0: string, 1: string}> */
    private static function changes(array $config, Model $model): array
    {
        $ignored = array_merge(ActivityFormatter::IGNORED, $config['ignore'] ?? []);
        $hidden = $model->getHidden();
        $changes = [];

        foreach (array_keys($model->getChanges()) as $field) {
            if (in_array($field, $ignored, true)) {
                continue;
            }
            if ($field === 'password' || in_array($field, $hidden, true)) {
                $changes['password'] = ['•••', '•••'];
                continue;
            }

            $old = ActivityFormatter::display($field, $model->getOriginal($field));
            $new = ActivityFormatter::display($field, $model->getAttribute($field));
            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }

    private static function skipped(array $config, Model $model, string $event): bool
    {
        return isset($config['skip']) && ($config['skip'])($model, $event);
    }

    public static function label(array $config, Model $model): string
    {
        $label = $config['label'];

        return (string) (is_callable($label) ? $label($model) : ($model->getAttribute($label) ?? "#{$model->getKey()}"));
    }

    private static function quotedLabel(array $config, Model $model): string
    {
        $label = self::label($config, $model);

        return !empty($config['quote']) ? "«{$label}»" : $label;
    }

    private static function key(Model $model): string
    {
        return $model::class . ':' . $model->getKey();
    }
}
