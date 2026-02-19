<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Enum\SubscriptionStatus;
use App\Form\SubscriptionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\SubscriptionRepository;
use App\Enum\OfferType;

class SubscriptionController extends AbstractController
{
    #[Route('/subscription/new', name: 'app_subscription_new')]
    public function new(Request $request, EntityManagerInterface $em, SubscriptionRepository $subscriptionRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $offerKey = (string) $request->query->get('offer', '');

        $offerType = match ($offerKey) {
            'start2' => OfferType::BASIC,
            'start3' => OfferType::PREMIUM,
            default => null,
        };

        if (!$offerType) {
            $this->addFlash('error', "Veuillez choisir une offre avant de souscrire.");
            return $this->redirectToRoute('app_offer_index');
        }

        $subscription = new Subscription();
        $subscription->setOfferType($offerType);

        $form = $this->createForm(SubscriptionType::class, $subscription);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $clientNameRaw = (string) $subscription->getClientName();
            $clientSlug = $this->toSubdomainSlug($clientNameRaw);

            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $clientSlug)) {
                $this->addFlash('error', "Nom de sous-domaine invalide (caractères autorisés : a-z, 0-9, tirets).");
                return $this->redirectToRoute('app_subscription_new', ['offer' => $offerKey]);
            }

            $domain = sprintf('%s.lutice.com', $clientSlug);

            if ($subscriptionRepository->findOneBy(['domain' => $domain])) {
                $this->addFlash('error', "Ce sous-domaine est déjà utilisé. Choisis un autre nom.");
                return $this->redirectToRoute('app_subscription_new', ['offer' => $offerKey]);
            }

            $subscription->setClientName($clientSlug);
            $subscription->setDomain($domain);
            $subscription->setStatus(SubscriptionStatus::PENDING);
            $subscription->setUser($this->getUser());

            $em->persist($subscription);
            $em->flush();

            return $this->redirectToRoute('subscription_pay', [
                'id' => $subscription->getId(),
            ]);
        }

        return $this->render('subscription/new.html.twig', [
            'form' => $form->createView(),
            'offerKey' => $offerKey,
        ]);
    }

    #[Route('/subscription/{id}/success', name: 'app_subscription_success')]
    public function success(Subscription $subscription): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$this->isGranted('ROLE_ADMIN') && $subscription->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('subscription/success.html.twig', [
            'subscription' => $subscription,
        ]);
    }

    private function toSubdomainSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[\s_]+/', '-', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9-]/', '', $value) ?? $value;
        $value = preg_replace('/-+/', '-', $value) ?? $value;
        $value = trim($value, '-');

        return $value;
    }
}
