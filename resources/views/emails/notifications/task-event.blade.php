@extends('emails.notifications.layouts.notification')

@section('body')
    <p>{{ $notificationMessage }}</p>

    <div class="details">
        <table>
            @if (!empty($task))
                <tr>
                    <td>Tarea</td>
                    <td>{{ $task->title ?? '—' }}</td>
                </tr>
                @if (!empty($task->description))
                    <tr>
                        <td>Descripción</td>
                        <td>{{ $task->description }}</td>
                    </tr>
                @endif
                @if (!empty($task->due_date))
                    <tr>
                        <td>Fecha límite</td>
                        <td>{{ \Carbon\Carbon::parse($task->due_date)->format('d/m/Y') }}</td>
                    </tr>
                @endif
            @endif
            @if (!empty($actor_name))
                <tr>
                    <td>Realizado por</td>
                    <td>{{ $actor_name }}</td>
                </tr>
            @endif
        </table>
    </div>
@endsection
