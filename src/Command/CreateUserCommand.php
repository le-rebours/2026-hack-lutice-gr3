<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Create a user (ROLE_USER by default, ROLE_ADMIN optional).',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'User password')
            ->addArgument('role', InputArgument::OPTIONAL, 'ROLE_USER or ROLE_ADMIN', 'ROLE_USER');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');
        $role = strtoupper((string) $input->getArgument('role'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('<error>Invalid email.</error>');
            return Command::FAILURE;
        }

        $repo = $this->em->getRepository(User::class);
        if ($repo->findOneBy(['email' => $email])) {
            $output->writeln('<error>User already exists.</error>');
            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);

        $roles = ['ROLE_USER'];
        if ($role === 'ROLE_ADMIN') {
            $roles[] = 'ROLE_ADMIN';
        }
        $user->setRoles(array_values(array_unique($roles)));

        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln('<info>User created: '.$email.'</info>');
        $output->writeln('<info>Roles: '.implode(', ', $user->getRoles()).'</info>');

        return Command::SUCCESS;
    }
}
