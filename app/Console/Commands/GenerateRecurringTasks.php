<?php

namespace App\Console\Commands;

use App\Services\TaskService;
use Illuminate\Console\Command;

class GenerateRecurringTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:generate-recurring';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate recurring tasks that are due';

    public function __construct(
        private TaskService $taskService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating recurring tasks...');

        $generated = $this->taskService->generateDueRecurringTasks();

        $this->info("Generated {$generated} recurring tasks.");

        // Mark overdue tasks
        $overdue = $this->taskService->markOverdueTasks();
        $this->info("Marked {$overdue} tasks as overdue.");

        // Send reminders
        $reminders = $this->taskService->sendDueTaskReminders();
        $this->info("Sent {$reminders} task reminders.");

        return Command::SUCCESS;
    }
}
