<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\SportabzeichenExam;
use PulsR\SportabzeichenBundle\Entity\SportabzeichenParticipant;
use PulsR\SportabzeichenBundle\Entity\SportabzeichenRequirement;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/sportabzeichen', name: 'sportabzeichen_')]
final class ManageController extends AbstractPageController
{
    #[Route(path: '/manage', name: 'manage')]
    public function index(EntityManagerInterface $em): Response
    {
        // Anzahl Prüfungen
        $exams = $em->getRepository(SportabzeichenExam::class)->count([]);

        // Anzahl Teilnehmer
        $participants = $em->getRepository(SportabzeichenParticipant::class)->count([]);

        // Anzahl verschiedener Jahre im Katalog
        $requirementYears = $em->getRepository(SportabzeichenRequirement::class)
            ->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.jahr)')
            ->getQuery()
            ->getSingleScalarResult();

        $stats = [
            'exams' => $exams,
            'participants' => $participants,
            'requirementYears' => $requirementYears,
        ];

        return $this->render('@PulsRSportabzeichen/manage/index.html.twig', [
            'title' => 'Sportabzeichen – Verwaltung',
            'stats' => $stats,
            'tabs' => [
                [
                    'label' => 'Verwaltung',
                    'route' => 'sportabzeichen_manage',
                    'active' => true
                ],
                [
                    'label' => 'Prüfungen',
                    'route' => 'sportabzeichen_exam_index',
                    'active' => false
                ],
                [
                    'label' => 'Teilnehmer',
                    'route' => 'sportabzeichen_participant_index',
                    'active' => false
                ],
            ]
        ]);
    }
}
