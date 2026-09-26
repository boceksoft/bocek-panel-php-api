<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;

/*
 * Homes management detail create resource.
 * Detail update endpoint'i ile ayni payload yapisini kullanarak yeni emlak olusturur.
 */
final class HomesManagementDetailCreateController extends HomesManagementDetailUpdateController
{
    /**
     * Yeni emlak olusturur.
     *
     * @Post
     */
    public function index(): void
    {
        $payload = $this->payload();
        $updates = $this->homesUpdates($payload);
        $mesafeler = $this->mesafelerPayload($payload);
        $hasBakimciContactPayload = $this->hasBakimciContactPayload($payload);

        if ($updates === [] && $mesafeler === [] && !$hasBakimciContactPayload) {
            throw new HttpException('Olusturulacak emlak alani bulunamadi.', 'VALIDATION', 422);
        }

        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $insertResult = $this->insertHome($pdo, $updates, $payload);
            $id = $insertResult['id'];
            $mesafelerResult = $this->updateMesafeler($pdo, $id, $mesafeler);
            $bakimciAssignmentResult = $this->syncBakimciAssignment($pdo, $id, $updates);
            $bakimciResult = $this->updateBakimciContact($pdo, $id, $payload);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new HttpException('Emlak olusturulamadi.', 'DB_CREATE_FAILED', 500, $e);
        }

        $this->response->success([
            'id' => $id,
            'created' => true,
            'inserted_columns' => $insertResult['inserted_columns'],
            'skipped_columns' => $insertResult['skipped_columns'],
            'updated_related_sections' => [
                'mesafeler' => $mesafelerResult['updated'],
                'bakimci_assignment' => $bakimciAssignmentResult['updated'],
                'bakimci' => $bakimciResult['updated'],
            ],
            'skipped_related_rows' => array_merge(
                $mesafelerResult['skipped'],
                $bakimciAssignmentResult['skipped'],
                $bakimciResult['skipped']
            ),
            'skipped_related_sections' => $this->skippedRelatedSections($payload),
        ], 201);
    }
}
