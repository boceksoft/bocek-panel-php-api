<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Updater;

/*
 * Kurulu sürüm bilgisi kaynağı — salt okunur, herkese açık (sır istemez).
 *   GET /backend-api/version
 * Hangi git tag'in (veya tag yoksa commit'in) kurulu olduğunu ve bu sürümde
 * neyin değiştiğini (son güncellemenin changelog'u) gösterir.
 * İzleme/monitoring için düşünüldü; güncelleme YAPMAZ (bkz. UpdateController).
 */
final class VersionController extends Controller
{
    /**
     * @Get
     */
    public function index(): void
    {
        $updater = new Updater(dirname(__DIR__, 2), $this->app);
        $state = $updater->readState();

        $this->response->success([
            'version'     => $state['version'] ?? null,
            'sha'         => $state['sha'] ?? null,
            'deployed_at' => $state['deployed_at'] ?? null,
            'changelog'   => $state['changelog'] ?? [],
        ]);
    }
}
