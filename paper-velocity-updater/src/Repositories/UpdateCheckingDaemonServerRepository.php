<?php

namespace Martindob\PaperVelocityUpdater\Repositories;

use App\Repositories\Daemon\DaemonServerRepository;
use Exception;
use Illuminate\Http\Client\Response;
use Martindob\PaperVelocityUpdater\Services\PaperVelocityUpdateService;

/**
 * Decorates the panel's own DaemonServerRepository so that a "start"/"restart"
 * power action applies whatever update was already staged in the background
 * (see PaperVelocityUpdateService::checkForUpdates(), run on a schedule),
 * right before the signal is forwarded to Wings. This covers both manual
 * power actions (console, client API) and the "Power Action" scheduled task,
 * since both resolve this class out of the container.
 */
class UpdateCheckingDaemonServerRepository extends DaemonServerRepository
{
    private const UPDATE_ON_SIGNALS = ['start', 'restart'];

    public function __construct(private readonly PaperVelocityUpdateService $updateService) {}

    /**
     * Applying a staged update is just a couple of fast rename calls (see
     * PaperVelocityUpdateService::applyPendingUpdate()), not a download, so
     * unlike the old design this never needs to abort or delay the actual
     * power action: any failure here is swallowed (and throttled-logged by
     * the service itself) and the signal is forwarded exactly as it would be
     * without this plugin. Worst case, the server starts on its current jar
     * and the swap is retried on the next restart.
     */
    public function power(string $action): Response
    {
        if (isset($this->server) && in_array($action, self::UPDATE_ON_SIGNALS, true)) {
            try {
                $this->updateService->applyPendingUpdate($this->server);
            } catch (Exception $exception) {
                report($exception);
            }
        }

        return parent::power($action);
    }
}
