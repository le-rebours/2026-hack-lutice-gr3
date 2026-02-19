<?php

namespace App\Controller;

use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard_index')]
    public function index(SubscriptionRepository $subscriptionRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $subs = $subscriptionRepository->findBy(
            ['user' => $this->getUser()],
            ['createdAt' => 'DESC']
        );

        return $this->render('dashboard/index.html.twig', [
            'subscriptions' => $subs,
        ]);
    }
}
