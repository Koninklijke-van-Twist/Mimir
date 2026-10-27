<?php

/**
 * Per tabel (OData-servicenaam) de bevestigde Normal-velden (geen FlowField).
 *
 * Alleen voor tabellen MET FlowFields (*_Filter-velden in de metadata): delta en
 * weekendverversing mogen daar alleen als elke gevraagde kolom (select, filter-
 * velden en sleutels) hier staat. Geen select (= alle kolommen), een kolom die
 * hier niet staat of een tabel die hier niet staat -> volledige fetch.
 *
 * Alleen velden opnemen die in BC als FieldClass = Normal zijn bevestigd.
 */

return [
    // Voorbeeld, nog NIET bevestigd (lijst volgt van Ariadne):
    // 'AppItems' => ['No', 'Description', 'Blocked', 'Last_Date_Modified'],
];
