<?php

namespace Tests\Feature\DespliegueOperacion;

use App\Jobs\SendNotificationJob;
use App\Mail\CashClosingMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Notifications\Contracts\NotificationTemplateInterface;
use App\Notifications\Services\NotificationSender;
use App\Notifications\Services\RecipientResolver;
use App\Services\FirebaseService;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

/**
 * La cola en producción (worker con --tries=3 --timeout=60 y sin --backoff):
 * reintentos con espera, límites de tiempo hacia los proveedores externos y
 * un reintento del aviso que no repite el envío a quien ya lo recibió.
 */
class QueueRetryTest extends TestCase
{
    use RefreshDatabase, CreatesTenant;

    /** Ids de usuario a los que el sender falso "envió", en orden. */
    private array $sentTo = [];

    /** Usuario cuyo envío falla en el primer intento (null = ninguno). */
    private ?int $failOnceFor = null;

    private function fakeSenderAndRecipients(Collection $recipients): void
    {
        $test = $this;

        $this->app->instance(NotificationSender::class, new class($this->app->make(FirebaseService::class), $test) extends NotificationSender {
            public function __construct(FirebaseService $firebase, private QueueRetryTest $test)
            {
                parent::__construct($firebase);
            }

            public function send(NotificationTemplateInterface $template, User $user, int $companyId, ?int $locationId = null): void
            {
                $this->test->recordSend($user->id);
            }
        });

        $this->app->instance(RecipientResolver::class, new class($recipients) extends RecipientResolver {
            public function __construct(private Collection $recipients) {}

            public function resolve(int $companyId, string $eventType, string $defaultPermission, ?int $specificUserId = null, ?int $locationId = null): Collection
            {
                return $this->recipients;
            }
        });
    }

    public function recordSend(int $userId): void
    {
        if ($this->failOnceFor === $userId) {
            $this->failOnceFor = null;
            throw new RuntimeException('FCM no respondió');
        }

        $this->sentTo[] = $userId;
    }

    /** Un job de la cola con uuid fijo: los reintentos de un job conservan su uuid. */
    private function queuedJob(string $uuid): SendNotificationJob
    {
        $job = new SendNotificationJob('reminder', $this->company->id, ['title' => 'Prueba', 'message' => 'Prueba']);
        $job->setJob(new class($uuid) extends FakeJob {
            public function __construct(private string $fixedUuid) {}

            public function uuid()
            {
                return $this->fixedUuid;
            }
        });

        return $job;
    }

    public function test_un_reintento_del_aviso_no_repite_el_envio_a_quien_ya_lo_recibio(): void
    {
        $this->createTenant();
        $second = $this->createBranchUser($this->location, ['sales_pos']);
        $third = $this->createBranchUser($this->location, ['sales_pos']);
        $this->fakeSenderAndRecipients(collect([$this->user, $second, $third]));

        // Primer intento: el envío al segundo destinatario truena a medias.
        $this->failOnceFor = $second->id;
        try {
            $this->app->call([$this->queuedJob('retry-uuid'), 'handle']);
            $this->fail('El job debía relanzar el error para que la cola lo reintente.');
        } catch (RuntimeException $e) {
            $this->assertSame('FCM no respondió', $e->getMessage());
        }
        $this->assertSame([$this->user->id], $this->sentTo);

        // El reintento (mismo uuid) sigue donde se quedó: el primero no recibe
        // el aviso dos veces.
        $this->app->call([$this->queuedJob('retry-uuid'), 'handle']);
        $this->assertSame([$this->user->id, $second->id, $third->id], $this->sentTo);

        // Otro evento (otro job, otra uuid) sí llega a todos.
        $this->app->call([$this->queuedJob('otro-uuid'), 'handle']);
        $this->assertCount(6, $this->sentTo);
    }

    public function test_los_trabajos_de_correo_y_avisos_reintentan_con_espera(): void
    {
        $job = new SendNotificationJob('reminder', 1, []);
        $this->assertGreaterThan(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);

        // Los mailables encolados: SendQueuedMailable toma tries/backoff del mailable.
        foreach ([CashClosingMail::class, PasswordResetMail::class] as $class) {
            $mailable = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $queued = new SendQueuedMailable($mailable);

            $this->assertGreaterThan(3, $queued->tries, $class);
            $this->assertSame([10, 60, 300], $queued->backoff(), $class);
        }

        // El código de recuperación dura 15 minutos: el último intento llega antes.
        $this->assertLessThan(PasswordResetMail::EXPIRATION_MINUTES * 60, array_sum((new PasswordResetMail(new User(), '123456'))->backoff));
    }

    public function test_el_correo_por_resend_y_smtp_tienen_limite_de_tiempo(): void
    {
        config(['services.resend.key' => 're_test_123', 'mail.mailers.resend.timeout' => 12]);

        $transport = Mail::mailer('resend')->getSymfonyTransport();
        $this->assertInstanceOf(ResendTransport::class, $transport);

        $client = (new ReflectionProperty($transport, 'resend'))->getValue($transport);
        $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);
        $guzzle = (new ReflectionProperty($transporter, 'client'))->getValue($transporter);

        $this->assertInstanceOf(GuzzleClient::class, $guzzle);
        $this->assertEquals(12, $guzzle->getConfig('timeout'));
        $this->assertEquals(5, $guzzle->getConfig('connect_timeout'));

        $this->assertSame(15, config('mail.mailers.smtp.timeout'));
    }

    public function test_firebase_tiene_limite_de_tiempo_y_no_escribe_tokens_completos_en_el_log(): void
    {
        $options = FirebaseService::httpClientOptions();
        $this->assertNotNull($options->connectTimeout());
        $this->assertNotNull($options->timeout());
        $this->assertLessThan(60, $options->timeout());

        $token = 'dXw3Zk1-abcdefghijklmnopqrstuvwxyz0123456789:APA91bH_final';
        $masked = FirebaseService::maskToken($token);
        $this->assertStringNotContainsString($token, $masked);
        $this->assertStringStartsWith('dXw3Zk1-', $masked);
        $this->assertStringEndsWith('inal', $masked);
        $this->assertSame('…', FirebaseService::maskToken('corto'));
    }
}
