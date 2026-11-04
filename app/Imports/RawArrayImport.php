<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Import nullo: serve solo a leggere i fogli come array con Excel::toArray().
 */
class RawArrayImport implements ToArray
{
    /**
     * @param  array<int, array<int, mixed>>  $array
     */
    public function array(array $array): void {}
}
