<?php

namespace App\Http\Controllers;

use App\Services\Referencement;
use Illuminate\Http\Response;

/** Plan du site, robots.txt et llms.txt : générés depuis les données publiées de la vitrine. */
class ReferencementController extends Controller
{
    public function plan(): Response
    {
        return response()
            ->view('referencement.plan', ['pages' => Referencement::pagesDuPlan()])
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    public function robots(): Response
    {
        return response(Referencement::robots())->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function llms(): Response
    {
        return response(Referencement::llms())->header('Content-Type', 'text/markdown; charset=utf-8');
    }
}
