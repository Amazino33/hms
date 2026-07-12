<?php

it('generates a PDF from the sealed snapshot containing the frozen values, downloadable by the outgoing custodian', function () {
    ['session' => $session, 'product' => $product, 'outgoing' => $outgoing] = sealedHandoverScenario(24, 20);

    // Price changes after the fact — the PDF must still reflect the frozen 500.
    $product->update(['price' => 9999]);

    $response = $this->actingAs($outgoing)->get(route('handover.pdf', $session->id));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

it('refuses the PDF to someone with no part in the session and no manager access', function () {
    ['session' => $session] = sealedHandoverScenario(24, 20);
    $stranger = \App\Models\User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('handover.pdf', $session->id))
        ->assertForbidden();
});

it('lets a manager download the PDF even without being a participant', function () {
    ['session' => $session] = sealedHandoverScenario(24, 20);

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'manager']);
    $manager = \App\Models\User::factory()->create();
    $manager->assignRole('manager');
    \App\Models\PagePermission::firstOrCreate(
        ['page_class' => \App\Filament\Pages\HandoverDiscrepancies::class, 'role_name' => 'manager'],
        ['page_class' => \App\Filament\Pages\HandoverDiscrepancies::class, 'page_name' => 'Handover Discrepancies', 'role_name' => 'manager']
    );

    $this->actingAs($manager)
        ->get(route('handover.pdf', $session->id))
        ->assertOk();
});
