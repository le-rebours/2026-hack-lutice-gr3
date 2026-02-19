<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OfferController extends AbstractController
{
    #[Route('/offers', name: 'app_offer_index')]
    public function index(): Response
    {
        $offers = require dirname(__DIR__, 2).'/config/lutice_offers.php';

        return $this->render('offer/index.html.twig', [
            'offers' => $offers,
        ]);
    }
}
