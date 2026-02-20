<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Enum\SubscriptionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[IsGranted('ROLE_USER')]
class SubscriptionPaymentController extends AbstractController
{
    private string $scwPath = '/usr/local/bin/scw';

    // Mets ça dans .env idéalement
    private string $scwProjectId;
    private string $scwZone;

    public function __construct()
    {
        // Tu peux aussi passer via parameters.yaml + DI si tu veux faire propre
        $this->scwProjectId = $_ENV['SCW_DEFAULT_PROJECT_ID'] ?? '';
        $this->scwZone      = $_ENV['SCW_DEFAULT_ZONE'] ?? 'fr-par-1';
    }

    #[Route('/subscription/{id}/pay', name: 'subscription_pay', methods: ['GET'])]
    public function pay(Subscription $subscription, EntityManagerInterface $em): Response
    {
        if ($subscription->getStatus() !== SubscriptionStatus::PENDING) {
            return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
        }

        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $amount = match ($subscription->getOfferType()->value ?? (string) $subscription->getOfferType()) {
            'start2' => 4900,
            'start3' => 9900,
            default  => 4900,
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
            'subscription'    => $subscription,
            'clientSecret'    => $intent->client_secret,
            'stripePublicKey' => $_ENV['STRIPE_PUBLIC_KEY'],
        ]);
    }

    #[Route('/subscription/{id}/payment/confirm', name: 'subscription_payment_confirm', methods: ['POST'])]
    public function confirm(
        Subscription $subscription,
        EntityManagerInterface $em,
        HttpClientInterface $httpClient
    ): RedirectResponse {
        if ($subscription->getStatus() !== SubscriptionStatus::PENDING) {
            return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
        }

        if (!$subscription->getStripePaymentIntentId()) {
            $this->addFlash('error', 'Aucun paiement initialisé.');
            return $this->redirectToRoute('subscription_pay', ['id' => $subscription->getId()]);
        }

        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
        $intent = PaymentIntent::retrieve($subscription->getStripePaymentIntentId());

        if ($intent->status !== 'succeeded') {
            $this->addFlash('error', 'Paiement non validé.');
            return $this->redirectToRoute('subscription_pay', ['id' => $subscription->getId()]);
        }

        // Paiement OK => on passe en provisioning et on tente Scaleway
        $subscription->setStatus(SubscriptionStatus::PROVISIONING);
        $subscription->setErrorMessage(null);
        $em->flush();

        // Sécurité: si pas de project id, on stoppe
        if (!$this->scwProjectId) {
            $subscription->setStatus(SubscriptionStatus::ERROR);
            $subscription->setErrorMessage('SCW_DEFAULT_PROJECT_ID manquant (env).');
            $em->flush();
            return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
        }

        // Slug + nom instance
        $clientName = (string) $subscription->getClientName();
        $clientSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($clientName)) ?? '');
        $clientSlug = trim($clientSlug, '-');
        $instanceName = "lutice-gr6-" . $clientSlug;

        try {
            // 1) Create server
            $cmdServer = sprintf(
                '%s instance server create name=%s type=DEV1-S image=debian_bookworm ip=none project-id=%s zone=%s -o json 2>&1',
                escapeshellcmd($this->scwPath),
                escapeshellarg($instanceName),
                escapeshellarg($this->scwProjectId),
                escapeshellarg($this->scwZone),
            );

            $outputServer = shell_exec($cmdServer);
            $dataServer = json_decode((string) $outputServer, true);
            $serverId = $dataServer['id'] ?? null;

            if (!$serverId) {
                $subscription->setStatus(SubscriptionStatus::ERROR);
                $subscription->setErrorMessage("Échec création serveur Scaleway: " . (string) $outputServer);
                $em->flush();
                return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
            }

            $subscription->setScaleWayServerId($serverId);
            $em->flush();

            // 2) Create IP
            $cmdIp = sprintf(
                '%s instance ip create zone=%s -o json 2>&1',
                escapeshellcmd($this->scwPath),
                escapeshellarg($this->scwZone),
            );

            $outputIp = shell_exec($cmdIp);
            $dataIp = json_decode((string) $outputIp, true);

            $ipId = $dataIp['id'] ?? ($dataIp['ip']['id'] ?? null);
            $ipAddress = $dataIp['address'] ?? ($dataIp['ip']['address'] ?? null);

            if (!$ipId || !$ipAddress) {
                $subscription->setStatus(SubscriptionStatus::ERROR);
                $subscription->setErrorMessage("Serveur créé mais échec création IP: " . (string) $outputIp);
                $em->flush();
                return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
            }

            // 3) Attach IP
            $cmdAttach = sprintf(
                '%s instance ip attach ip=%s server-id=%s zone=%s -o json 2>&1',
                escapeshellcmd($this->scwPath),
                escapeshellarg($ipId),
                escapeshellarg($serverId),
                escapeshellarg($this->scwZone),
            );
            shell_exec($cmdAttach);

            // On enregistre la vraie IP publique Scaleway
            $subscription->setPublicIp($ipAddress);
            $em->flush();

            // 4) Call deploy endpoint (Ansible / API réseau)
            try {
                $response = $httpClient->request('POST', 'http://51.15.202.63:8000/deploy', [
                    'json' => [
                        'ip'    => $ipAddress,
                        'email' => $subscription->getClientEmail(),
                        'name'  => $clientName,
                    ],
                    'timeout' => 10,
                ]);

                $code = $response->getStatusCode();
                if ($code >= 200 && $code < 300) {
                    $subscription->setStatus(SubscriptionStatus::READY);
                } else {
                    // Déploiement pas confirmé => on reste en provisioning (ou mets un status DEPLOYING si tu l’as)
                    $subscription->setStatus(SubscriptionStatus::PROVISIONING);
                }
            } catch (\Throwable $e) {
                $subscription->setStatus(SubscriptionStatus::PROVISIONING);
            }

            $em->flush();
        } catch (\Throwable $e) {
            $subscription->setStatus(SubscriptionStatus::ERROR);
            $subscription->setErrorMessage($e->getMessage());
            $em->flush();
        }

        return $this->redirectToRoute('app_subscription_success', ['id' => $subscription->getId()]);
    }
}
