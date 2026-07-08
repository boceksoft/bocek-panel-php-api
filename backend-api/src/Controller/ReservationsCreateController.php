<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

final class ReservationsCreateController extends Controller
{
    /**
     * @Post
     * @query page int Sayfa numarası
     * @query per_page int Sayfa başına kayıt (0 veya "tumu" => tümü)
     * @query durum int Rezervasyon durumu (çoklu olabilir)
     * @query musteri string Müşteri adı/kelime araması
     */
    public function index(): void
    {
        $pdo = $this->db->pdo();

        $start      = $this->request->input('start', []);



    }
}
