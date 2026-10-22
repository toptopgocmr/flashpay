<?php

namespace App\Services\Remit;

use App\Services\Peex\PeexException;

/** Partenaire de remittance qui ne répond pas (réseau, erreur serveur) avant tout débit : on essaie le secours. */
class RemitPartnerDown extends PeexException
{
}
