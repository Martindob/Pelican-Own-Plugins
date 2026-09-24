<?php

namespace Martindob\PaperVelocityUpdater\Repositories;

use App\Repositories\Daemon\DaemonServerRepository;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Martindob\PaperVelocityUpdater\Services\PaperVelocityUpdateService;

/**
 * Decorates the panel's own DaemonServerRepository so that a "start"/"restart"
 * power action runs the Paper/Velocity update check first, before the signal
 * is forwarded to Wings. This covers both manual power actions (console,
 * client API) and the "Power Action" scheduled task, since both resolve this
 * class out of the container.
 */
class UpdateCheckingDaemonServerRepository extends DaemonServerRepository
{
    private const UPDATE_ON_SIGNALS = ['start', 'restart'];

    public function __construct(private readonly PaperVelocityUpdateService $updateService) {}

    /**
     * If the update check itself fails (PaperMC/daemon unreachable, ...) this still
     * proceeds to the actual power signal - see PaperVelocityUpdateService::maybeUpdate().
     * A failed *download*, however, is deliberately allowed to abort this method
     * entirely: never send "start"/"restart" to Wings while the jar it's about to
     * run might still be mid-write on the daemon.
     *
     * Every panel call site that sends a power action (e.g.
     * ListServers::powerAction() in the client dashboard) only catches
     * ConnectionException to show a graceful error notification - it has no idea
     * this plugin exists. So whatever actually failed here (a RequestException
     * from the daemon rejecting the pull, a LockTimeoutException from another
     * in-flight check on the same server, ...) is re-thrown as a
     * ConnectionException, which is the one type every such call site already
     * handles. The original exception is kept as the "previous" one so nothing
     * is lost for debugging/reporting.
     *
     * @throws ConnectionException
     */
    public function power(string $action): Response
    {
        if (isset($this->server) && in_array($action, self::UPDATE_ON_SIGNALS, true)) {
            try {
                $this->updateService->maybeUpdate($this->server);
            } catch (ConnectionException $exception) {
                throw $exception;
            } catch (Exception $exception) {
                // Not reported here: letting this propagate uncaught means the
                // framework's own exception handler logs it once, with the
                // original exception preserved as the "previous" cause - no need
                // to log it a second time ourselves.
                throw new ConnectionException(
                    "paper-velocity-updater: aborting {$action} - {$exception->getMessage()}",
                    previous: $exception
                );
            }
        }

        return parent::power($action);
    }
}
