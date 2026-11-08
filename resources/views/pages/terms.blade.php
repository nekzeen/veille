<x-guest-layout>
    <h1 class="text-xl font-semibold mb-1">Conditions d'utilisation</h1>
    @if (str_starts_with($version, 'draft'))
        <p class="mb-3 text-xs font-medium text-amber-700 bg-amber-50 rounded-md px-3 py-2">
            Document provisoire en cours de consolidation juridique — non définitif.</p>
    @endif
    <p class="text-xs text-gray-500 mb-4">Version {{ $version }} — mise à jour du 5 novembre 2026 —
        <a class="underline" href="{{ route('privacy') }}">politique de confidentialité</a></p>

    <div class="text-sm text-gray-800 space-y-3">
        <p>Veille est un service personnel d'agrégation de veille édité par
        <strong>Gaël Morvan</strong>, personne physique, projet non professionnel —
        <a class="underline" href="mailto:contact@gaelmorvan.fr">contact@gaelmorvan.fr</a>.</p>

        <h2 class="font-semibold pt-2">1. Le service</h2>
        <p>Veille permet de créer des veilles (mots-clés + sources), de collecter des
        articles RSS/Atom, de les consulter, classer, archiver, annoter, exclure, et de
        créer des liens de partage en lecture seule. Le service est
        <strong>actuellement gratuit</strong> ; toute évolution vers une offre payante
        fera l'objet d'une information préalable et de conditions séparées.</p>

        <h2 class="font-semibold pt-2">2. Compte</h2>
        <p>Adresse e-mail valide et mot de passe requis ; la vérification de l'e-mail
        est obligatoire. Vous êtes responsable de la confidentialité de vos identifiants
        et de l'usage de votre compte.</p>

        <h2 class="font-semibold pt-2">3. Vos responsabilités</h2>
        <p>Vous êtes seul responsable des sources et mots-clés configurés, de vos notes
        et contenus, des liens de partage émis et des contenus ainsi rendus accessibles.
        Un lien actif permet à quiconque le détient de consulter les contenus partagés.</p>

        <h2 class="font-semibold pt-2">4. Usages interdits</h2>
        <p>Usages illicites, atteinte aux droits de tiers, surcharge ou automatisation
        abusive, accès aux données d'autres utilisateurs, contournement des
        protections, revente non autorisée — interdits.</p>

        <h2 class="font-semibold pt-2">5. Contenus tiers</h2>
        <p>Les articles proviennent de sources externes dont Veille ne garantit ni
        disponibilité ni exactitude ; ils restent la propriété de leurs éditeurs.
        Les liens renvoient vers les originaux.</p>

        <h2 class="font-semibold pt-2">6. Partages</h2>
        <p>Révocables à tout moment, expiration possible ; une fois révoqués ou
        expirés, ils n'ouvrent plus aucun accès.</p>

        <h2 class="font-semibold pt-2">7. Suspension et suppression</h2>
        <p>Suspension possible en cas d'abus caractérisé. Vous pouvez supprimer votre
        compte à tout moment : vos données personnelles actives sont supprimées
        (sauvegardes : voir la politique de confidentialité).</p>

        <h2 class="font-semibold pt-2">8. Disponibilité</h2>
        <p>Service fourni « en l'état », sans garantie de disponibilité permanente,
        adapté à un projet personnel. Évolutions et interruptions possibles.</p>

        <p class="pt-2 text-gray-500">Droit français applicable. Ces conditions sont
        en cours de consolidation juridique — version {{ $version }}.</p>
    </div>
</x-guest-layout>
