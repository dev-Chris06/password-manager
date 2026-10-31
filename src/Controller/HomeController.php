<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('home.html.twig');
        // The page carries a per-request CSP nonce and must never be served from a cache.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
