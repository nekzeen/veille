<x-guest-layout>
    <h1 class="text-xl font-semibold mb-1">Politique de confidentialité</h1>
    @if (str_starts_with($version, 'draft'))
        <p class="mb-3 text-xs font-medium text-amber-700 bg-amber-50 rounded-md px-3 py-2">
            Document provisoire en cours de consolidation juridique — non définitif.</p>
    @endif
    <p class="text-xs text-gray-500 mb-4">Version {{ $version }} — mise à jour du 5 novembre 2026 —
        <a class="underline" href="{{ route('terms') }}">conditions d'utilisation</a></p>

    <div class="text-sm text-gray-800 space-y-3">
        <p>Responsable de traitement : <strong>Gaël Morvan</strong> —
        <a class="underline" href="mailto:contact@gaelmorvan.fr">contact@gaelmorvan.fr</a>.</p>

        <h2 class="font-semibold pt-2">1. Données traitées</h2>
        <ul class="list-disc pl-5 space-y-1">
            <li>Compte : nom, adresse e-mail, mot de passe haché ;</li>
            <li>Session : adresse IP, agent utilisateur ;</li>
            <li>Veilles, mots-clés, sources personnelles, états de lecture, favoris, archives, notes, catégories ;</li>
            <li>Règles d'exclusion, paramètres et liens de partage ;</li>
            <li>Digests d'alertes par e-mail ;</li>
            <li>Journaux techniques du serveur (IP, URLs consultées).</li>
        </ul>

        <h2 class="font-semibold pt-2">2. Finalités et bases</h2>
        <p>Exécution du service (art. 6.1.b RGPD) pour le compte, les veilles, les
        états, les partages et les digests ; intérêt légitime (art. 6.1.f) pour les
        sessions et journaux techniques. Aucun traitement n'est fondé sur un
        consentement générique.</p>

        <h2 class="font-semibold pt-2">3. Conservation</h2>
        <ul class="list-disc pl-5 space-y-1">
            <li>Articles : visibilité de 6 mois ; les lignes ne sont pas supprimées
            automatiquement de la base ;</li>
            <li>Sessions : environ 120 minutes (nettoyage technique probabiliste) ;</li>
            <li>Sauvegardes automatiques : 7 jours maximum ;</li>
            <li>Sauvegardes manuelles historiques : durée indéfinie, exception
            temporaire en cours de résolution ;</li>
            <li>Journaux serveur : contiennent IP et URLs (y compris jetons de
            partage) ; durée non fixée par l'application.</li>
        </ul>

        <h2 class="font-semibold pt-2">4. Suppression de compte</h2>
        <p>Suppression immédiate des données actives (veilles, états, notes,
        exclusions, partages, sessions). Les sauvegardes existantes peuvent contenir
        des copies jusqu'à leur destruction selon leur rétention.</p>

        <h2 class="font-semibold pt-2">5. Destinataires</h2>
        <ul class="list-disc pl-5 space-y-1">
            <li>Hébergement : <strong>OVH SAS</strong>, 2 rue Kellermann, 59100
            Roubaix, France ;</li>
            <li>E-mails : serveur Postfix local — aucun prestataire externe ;</li>
            <li>Aucun outil d'analyse d'audience ni traceur publicitaire.</li>
        </ul>
        <p>Les liens externes (articles originaux, sources collectées) impliquent des
        échanges avec des serveurs tiers hors de notre contrôle.</p>

        <h2 class="font-semibold pt-2">6. Vos droits</h2>
        <p>Accès, rectification, effacement, limitation, opposition, portabilité —
        via <a class="underline" href="mailto:contact@gaelmorvan.fr">contact@gaelmorvan.fr</a>.
        Réclamation : CNIL (cnil.fr).</p>

        <p class="pt-2 text-gray-500">Ce document est en cours de consolidation
        juridique — version {{ $version }}. Toute activation de nouveaux traitements
        fera l'objet d'une mise à jour préalable.</p>
    </div>
</x-guest-layout>
