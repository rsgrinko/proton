<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Note;
use Rsgrinko\Proton\Access\Scope;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Response;

/**
 * Главная страница вошедшего: что у него есть и куда идти дальше.
 */
final class HomeController extends Controller
{
    public function index(Viewer $viewer, Scope $scope): Response
    {
        return $this->view('home', [
            'active' => 'home',
            'viewer' => $viewer,
            'notes'  => $scope->apply(Note::query())->count(),
            'latest' => $scope->apply(Note::query())->orderBy('id', 'desc')->limit(5)->get(),
        ], 'Главная');
    }
}
