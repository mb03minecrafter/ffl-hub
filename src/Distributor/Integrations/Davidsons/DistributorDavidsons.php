<?php

namespace FFLHub\Distributor\Integrations\Davidsons;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;

/**
 * Minimal Davidson's runtime distributor.
 *
 * This intentionally relies on DistributorBase defaults until
 * Davidson's API/table services are implemented.
 */
final class DistributorDavidsons extends DistributorBase
{
}

