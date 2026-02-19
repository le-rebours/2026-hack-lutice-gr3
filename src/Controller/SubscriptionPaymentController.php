<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Enum\SubscriptionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SubscriptionPaymentController extends AbstractController
{
    #[Route('/subscription/{id}/pay', name: 'subscription_pay', methods: ['GET'])]
    public function pay(Subscription $subscription, EntityManagerInterface $em): Response
    {
        if ($subscription->getStatus() !== SubscriptionStatus::PENDING) {
            return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
        }

        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $amount = match ($subscription->getOfferType()->value) {
            'start2' => 4900,
            'start3' => 9900,
            default => 4900,
        };

        if (!$subscription->getStripePaymentIntentId()) {
            $intent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'eur',
                'payment_method_types' => ['card'],
                'metadata' => [
                    'subscription_id' => (string) $subscription->getId(),
                    'user_id' => (string) $subscription->getUser()->getId(),
                ],
            ]);

            $subscription->setStripePaymentIntentId($intent->id);
            $em->flush();
        } else {
            $intent = PaymentIntent::retrieve($subscription->getStripePaymentIntentId());
        }

        return $this->render('subscription/pay.html.twig', [
            'subscription' => $subscription,
            'clientSecret' => $intent->client_secret,
            'stripePublicKey' => $_ENV['STRIPE_PUBLIC_KEY'],
        ]);
    }

    #[Route('/subscription/{id}/payment/confirm', name: 'subscription_payment_confirm', methods: ['POST'])]
    public function confirm(Subscription $subscription, EntityManagerInterface $em): RedirectResponse
    {
        if ($subscription->getStatus() !== SubscriptionStatus::PENDING) {
            return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
        }

        if (!$subscription->getStripePaymentIntentId()) {
            $this->addFlash('error', 'Aucun paiement initialisé.');
            return $this->redirectToRoute('subscription_pay', ['id' => $subscription->getId()]);
        }

        \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
        $intent = \Stripe\PaymentIntent::retrieve($subscription->getStripePaymentIntentId());

        if ($intent->status !== 'succeeded') {
            $this->addFlash('error', 'Paiement simulé non validé.');
            return $this->redirectToRoute('subscription_pay', ['id' => $subscription->getId()]);
        }

        $subscription->setStatus(SubscriptionStatus::PROVISIONING);
        $subscription->setErrorMessage(null);
        $em->flush();

        return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
    }
}
