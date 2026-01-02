/**
 * @Route("/", name="index")
 */
public function index(Request $request, ParticipantRepository $repo): Response
{
    // 1. Minimalste Abfrage: Keine Klasse, nur Name, Limit 10!
    $participants = $repo->createQueryBuilder('p')
        ->select('p.id, p.vorname, p.nachname, p.geburtsdatum, p.geschlecht') // KEIN p.klasse
        ->orderBy('p.nachname', 'ASC')
        ->setMaxResults(10) // Nur 10 Einträge!
        ->getQuery()
        ->getArrayResult();

    // Wir übergeben KEINE Berechnungen (totalCount etc.), nur das rohe Array
    return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
        'participants' => $participants,
        // 'activeTab' => 'participants_manage', // AUSKOMMENTIERT: Wir testen ohne Tabs-Logik
        'currentPage' => 1,
        'maxPages' => 1,
        'totalCount' => 10
    ]);
}