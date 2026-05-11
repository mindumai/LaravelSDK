<?php

declare(strict_types=1);

namespace Mindum\Laravel\Commands;

use Illuminate\Console\Command;

/**
 * `php artisan mindum:chat`
 *
 * Reserved for the local chat REPL — talks to the orchestration API's
 * chat endpoint and lets a developer try their tools from the terminal
 * without setting up the widget. The orchestration chat endpoint is
 * post-MVP (see Docs/Mindum_Project_Status.md §9 MVP Simplifications),
 * so this command currently prints a "not yet" message rather than 404.
 */
class ChatCommand extends Command
{
    protected $signature = 'mindum:chat';

    protected $description = 'Open an interactive chat with your Mindum-installed agent (post-MVP).';

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>mindum:chat</> — coming after Milestone 1.');
        $this->newLine();
        $this->line('  Mindum Milestone 1 ships the install/rescan loop only. The chat');
        $this->line('  orchestration endpoint (`/api/chat`) lands in a later milestone.');
        $this->newLine();
        $this->line('  For now, you can exercise a tool directly with tinker:');
        $this->line('    <fg=gray>php artisan tinker</>');
        $this->line('    <fg=gray>>>> $tool = app(\\'.config('mindum.tools_namespace').'\\ListPosts::class);</>');
        $this->line('    <fg=gray>>>> $tool->handle([]);</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
