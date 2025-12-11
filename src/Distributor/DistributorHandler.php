<?php

namespace FFLHub\Distributor;

use FFLHub\Distributor\DistributorInterface;
use FFLHub\Distributor\RSR\DistributorRSR;
use FFLHub\Distributor\Lipseys\DistributorLipseys;

if (! defined('ABSPATH')) {
    exit;
}


class DistributorHandler {

    /** @var array<string, DistributorBase> */
    private array $distributors = [];

    public function __construct()
    {
        $this->register_distributors();
    }


    //register and instantiate the distributors 
    private function register_distributors(): void
    {
        $this->distributors['rsr']     = new DistributorRSR();
        $this->distributors['lipseys'] = new DistributorLipseys();
    }

    /**
     * Get all registered distributor instances.
     *
     * @return array<string, DistributorBase>
     */
    public function get_distributors(): array
    {
        return $this->distributors;
    }

    /**
     * Get a distributor by its ID (e.g. 'lipseys', 'rsr').
     *
     * @param string $id
     * @return DistributorInterface|null
     */
    public function get_distributor_by_id(string $id): ?DistributorBase
    {
        foreach ($this->distributors as $dist) {
            if ($dist->get_id() === $id) {
                return $dist;
            }
        }

        return null;
    }

    


}



