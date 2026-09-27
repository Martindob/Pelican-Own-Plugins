<?php

namespace Boy132\MinecraftModrinth\Console\Commands;

use App\Models\Server;
use Boy132\MinecraftModrinth\GitHub\CiStatus;
use Boy132\MinecraftModrinth\GitHub\GitHubOffers;
use Boy132\MinecraftModrinth\GitHub\GitHubPluginService;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hourly: count what the GitHub repository offers every Paper/Velocity server (new plugins and
 * updates) for the navigation badge, and announce new plugins once per server. Reads the
 * repository state once for all servers; installs nothing.
 */
class CheckGitHubOffersCommand extends Command
{
    protected $signature = 'p:minecraft-modrinth:github-offers';

    protected $description = 'Checks which plugins of the GitHub repository source are new or have updates for each server, and notifies server owners once about new ones. Installs nothing.';

    public function handle(): int
    {
        $github = app(GitHubPluginService::class);
        $offers = app(GitHubOffers::class);

        if (!$github->isConfigured()) {
            $this->line('The GitHub repository source is disabled or not configured.');

            return 0;
        }

        try {
            $snapshot = $github->getSnapshot();
        } catch (GitHubSourceException $exception) {
            // Already reported (throttled) by the service. The cached counts simply expire.
            $this->warn('GitHub source unavailable: '.$exception->getMessage());

            return 0;
        }

        // Only announce what can actually be installed right now; with CI pending or red the next
        // run announces it once CI is green.
        $announce = !$github->requiresGreenCi() || $snapshot['ci'] === CiStatus::Green;

        if (!$offers->isStoreAvailable()) {
            $this->warn('Table '.GitHubOffers::TABLE.' is missing (plugin migration not run): counting offers, but not notifying anyone.');
        }

        Server::query()->with(['egg', 'node', 'transfer', 'user', 'subusers.user'])->chunkById(50, function ($servers) use ($github, $offers, $snapshot, $announce) {
            foreach ($servers as $server) {
                // One broken server must never stop the check for all the others.
                try {
                    $result = $offers->checkServer($server, $snapshot, $announce);
                } catch (Throwable $exception) {
                    $github->reportOncePerWindow("offers:{$server->id}:unexpected:".get_class($exception), $exception);
                    $this->warn("Server #{$server->id}: skipped after an error (".get_class($exception).').');

                    continue;
                }

                if ($result !== null && ($result['new'] > 0 || $result['updates'] > 0)) {
                    $this->line("Server #{$server->id}: {$result['new']} new, {$result['updates']} update(s)".(empty($result['announced']) ? '' : '; announced '.implode(', ', $result['announced'])).'.');
                }
            }
        });

        return 0;
    }
}
