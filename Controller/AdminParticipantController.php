/**
     * Zeigt IServ-Nutzer an, die noch NICHT in der Teilnehmerliste sind.
     * Optimiert für Speicherplatz (lädt nicht alle User).
     *
     * @Route("/missing", name="missing")
     */
    public function missing(Request $request, ParticipantRepository $pRepo, UserRepository $uRepo): Response
    {
        // 1. Nur die IDs der bereits vorhandenen Teilnehmer holen (sehr speicherschonend)
        // Wir nutzen DQL, um nur eine Liste von Integers zu bekommen.
        $existingIds = $pRepo->createQueryBuilder('p')
            ->select('IDENTITY(p.user)')
            ->getQuery()
            ->getScalarResult();
        
        // Das Ergebnis ist ein Array von Arrays, wir brauchen ein flaches Array von IDs.
        $excludeIds = array_column($existingIds, 1);

        // 2. QueryBuilder für User erstellen
        // Wir nutzen NICHT findAllActive(), da das keine Limits erlaubt.
        $qb = $uRepo->createQueryBuilder('u')
            ->where('u.act = true') // 'act' ist das IServ-Standardfeld für aktive User
            ->orderBy('u.username', 'ASC')
            ->setMaxResults(200); // WICHTIG: Hard-Limit setzen, damit der Speicher nicht vollläuft!

        // Wenn es bereits Teilnehmer gibt, schließen wir diese per SQL aus
        if (!empty($excludeIds)) {
            $qb->andWhere($qb->expr()->notIn('u.id', $excludeIds));
        }

        // Optional: Server-seitige Suche (falls du das Suchfeld im Template umbaust)
        $q = $request->query->get('q');
        if ($q) {
            $qb->andWhere('u.username LIKE :q OR u.firstname LIKE :q OR u.lastname LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        $missingUsers = $qb->getQuery()->getResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $missingUsers,
            'activeTab' => 'participants_manage',
            'limit_reached' => count($missingUsers) >= 200, // Info ans Template übergeben
        ]);
    }