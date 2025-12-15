<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use IServ\CoreBundle\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use PulsR\SportabzeichenBundle\Entity\SportabzeichenExamParticipant;
use PulsR\SportabzeichenBundle\Entity\SportabzeichenExamResult;
use PulsR\SportabzeichenBundle\Entity\SportabzeichenRequirement;

#[Route("/sportabzeichen/results")]
class ResultController extends AbstractController
{
    #[Route("/{ep}/", name: "sportabzeichen_results_for_participant")]
    public function index(SportabzeichenExamParticipant $ep)
    {
        return $this->render("@Sportabzeichen/results/index.html.twig", [
            'ep' => $ep,
            'results' => $ep->getResults(),
        ]);
    }

    #[Route("/{ep}/add", name: "sportabzeichen_add_result")]
    public function add(
        Request $request,
        SportabzeichenExamParticipant $ep
    ) {
        if ($request->isMethod("POST")) {

            $result = new SportabzeichenExamResult();
            $result->setExamParticipant($ep);
            $result->setDisziplin($request->get("disziplin"));
            $result->setKategorie($request->get("kategorie"));
            $result->setAuswahlnummer((int)$request->get("auswahlnummer"));
            $result->setLeistung((float)$request->get("leistung"));

            // Leistungsstufe automatisch ermitteln
            $reqRepo = $this->getDoctrine()->getRepository(SportabzeichenRequirement::class);
            $req = $reqRepo->findOneBy([
                'jahr' => $ep->getExam()->getExamYear(),
                'altersklasse' => $ep->getParticipant()->getAltersklasseForYear($ep->getExam()->getExamYear()),
                'geschlecht' => strtoupper($ep->getParticipant()->getGeschlecht()),
                'disziplin' => $result->getDisziplin(),
                'auswahlnummer' => $result->getAuswahlnummer(),
            ]);

            if ($req) {
                $result->setStufe($this->calculateLevel($req, $result->getLeistung()));
            }

            $this->getDoctrine()->getManager()->persist($result);
            $this->getDoctrine()->getManager()->flush();

            return $this->redirectToRoute("sportabzeichen_results_for_participant", ['ep' => $ep->getId()]);
        }

        return $this->render("@Sportabzeichen/results/add.html.twig", [
            'ep' => $ep
        ]);
    }

    private function calculateLevel(SportabzeichenRequirement $req, float $leistung): ?string
    {
        if ($req->getGold() !== null && $leistung >= $req->getGold()) return "Gold";
        if ($req->getSilber() !== null && $leistung >= $req->getSilber()) return "Silber";
        if ($req->getBronze() !== null && $leistung >= $req->getBronze()) return "Bronze";
        return null;
    }
}
