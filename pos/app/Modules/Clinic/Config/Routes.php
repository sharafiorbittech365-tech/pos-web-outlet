<?php

/**
 * Clinic (module "clinic"): appointments & queue, patients, prescriptions, doctors, setup.
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */
$routes->group('clinic', ['namespace' => 'App\Modules\Clinic\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:clinic']], static function ($routes): void {
    // front desk
    $routes->get('/', 'AppointmentsController::home');
    $routes->get('appointments', 'AppointmentsController::index', ['filter' => 'can:clinic.appointments']);
    $routes->get('slots', 'AppointmentsController::slots', ['filter' => 'can:clinic.appointments']);
    $routes->post('appointments', 'AppointmentsController::store', ['filter' => 'can:clinic.appointments']);
    $routes->post('appointments/(:num)/step', 'AppointmentsController::step/$1', ['filter' => 'can:clinic.appointments']);

    // patients: profile = front desk; record (visits with prescriptions) = clinic.patients.view, checked in the service
    $routes->get('patients', 'PatientsController::index', ['filter' => 'can:clinic.appointments']);
    $routes->get('patients/search', 'PatientsController::search', ['filter' => 'can:clinic.appointments']);
    $routes->post('patients', 'PatientsController::store', ['filter' => 'can:clinic.appointments']);
    $routes->get('patients/(:num)', 'PatientsController::show/$1', ['filter' => 'can:clinic.appointments']);
    $routes->post('patients/(:num)', 'PatientsController::update/$1', ['filter' => 'can:clinic.appointments']);

    // prescriptions
    // list & new: clinic.prescribe or clinic.patients.view (checked in the controller)
    $routes->get('prescriptions', 'PrescriptionsController::index');
    $routes->get('prescriptions/new', 'PrescriptionsController::create', ['filter' => 'can:clinic.prescribe']);
    $routes->post('rx', 'PrescriptionsController::start', ['filter' => 'can:clinic.prescribe']);
    $routes->get('rx/(:num)/items', 'PrescriptionsController::items/$1', ['filter' => 'can:clinic.prescribe']);
    $routes->get('rx/medicines', 'PrescriptionsController::medicines', ['filter' => 'can:clinic.prescribe']);
    // a medical record: clinic.patients.view, or the prescriber who wrote it (PrescriptionService::open)
    $routes->get('rx/(:num)', 'PrescriptionsController::show/$1');
    $routes->get('rx/(:num)/print', 'PrescriptionsController::print/$1');
    $routes->post('rx/(:num)', 'PrescriptionsController::save/$1', ['filter' => 'can:clinic.prescribe']);
    $routes->post('rx/(:num)/revise', 'PrescriptionsController::revise/$1', ['filter' => 'can:clinic.prescribe']);
    $routes->post('rx/(:num)/discard', 'PrescriptionsController::discard/$1', ['filter' => 'can:clinic.prescribe']);

    // doctors & setup
    $routes->get('doctors', 'DoctorsController::index', ['filter' => 'can:clinic.manage']);
    $routes->post('doctors', 'DoctorsController::store', ['filter' => 'can:clinic.manage']);
    $routes->get('doctors/(:num)', 'DoctorsController::edit/$1', ['filter' => 'can:clinic.manage']);
    $routes->post('doctors/(:num)', 'DoctorsController::update/$1', ['filter' => 'can:clinic.manage']);
    $routes->post('doctors/(:num)/delete', 'DoctorsController::delete/$1', ['filter' => 'can:clinic.manage']);
    $routes->post('doctors/(:num)/schedule', 'DoctorsController::schedule/$1', ['filter' => 'can:clinic.manage']);
    $routes->post('doctors/(:num)/leaves', 'DoctorsController::addLeave/$1', ['filter' => 'can:clinic.manage']);
    $routes->post('doctors/(:num)/leaves/(:num)/delete', 'DoctorsController::deleteLeave/$1/$2', ['filter' => 'can:clinic.manage']);
    $routes->get('setup', 'SetupController::index', ['filter' => 'can:clinic.manage']);
    $routes->post('setup/lists', 'SetupController::addList', ['filter' => 'can:clinic.manage']);
    $routes->post('setup/lists/(:num)/delete', 'SetupController::deleteList/$1', ['filter' => 'can:clinic.manage']);
    $routes->post('setup/tests', 'SetupController::addTests', ['filter' => 'can:clinic.manage']);
    $routes->post('setup/tests/(:num)/toggle', 'SetupController::toggleTest/$1', ['filter' => 'can:clinic.manage']);
    $routes->post('setup/pad', 'SetupController::savePad', ['filter' => 'can:clinic.manage']);
    $routes->post('setup/pad/reset', 'SetupController::resetPad', ['filter' => 'can:clinic.manage']);

    // images (doctor photo, signature, pad header / footer) for the back office and print pages
    $routes->get('files/(:num)', 'SetupController::file/$1');
});
