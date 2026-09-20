<?php
/**
 * derive_products.php — Extract products from contract register entries
 * and map them to LGAM capabilities and business areas.
 *
 * Usage: php derive_products.php [--reset]
 */
require_once __DIR__ . '/config.php';
$pdo = db();

$reset = in_array('--reset', $argv ?? []);
if ($reset) {
    $pdo->exec('DELETE FROM ct_lgam_entry_products');
    $pdo->exec('DELETE FROM ct_lgam_product_capabilities');
    $pdo->exec('DELETE FROM ct_lgam_product_areas');
    $pdo->exec('DELETE FROM ct_lgam_products');
    echo "Reset: all product tables cleared.\n";
}

// ─── PRODUCT DEFINITIONS ──────────────────────────────────────────────────
// Each entry: supplier_canon => [ product_name => [patterns, capabilities, areas, description] ]
// Capabilities use l2 node IDs: forms, workflow, payments, booking, identity, agentic-ai, email-cap, messaging-cap, telephony
// Business areas use l3 node IDs: adult-care, childrens, democratic, education, highways, housing, leisure, licensing, planning, public-health, revenues, waste
// Corporate areas use l4 node IDs: biz-planning, comms, governance, crm, facilities, financial, geo, legal, hr, procurement
// Foundational uses l5a-e IDs: ai-enablement, conversational-ai, generative-ai, intelligent-auto, ml, release-mgmt, monitoring, unified-comms, end-user-devices, knowledge-mgmt, productivity, app-portfolio, it-ops, sw-asset, compute, connectivity, virtualisation
// Integration: api-mgmt, data-pipelines, event-streaming, file-transfer, integration-gov, middleware, b2b-integration
// Security: app-data-sec, iam, incident-forensics, network-sec, physical-sec, soc, vuln-mgmt
// Data: bi-analytics, data-catalogue, data-governance, data-science, data-storage, doc-records, enterprise-search, geospatial

$PRODUCTS = [
    'Civica UK' => [
        'Spydus' => [
            'patterns' => ['spydus', 'library management'],
            'capabilities' => ['booking'],
            'areas' => ['leisure'],
            'desc' => 'Library management system'
        ],
        'Modern.Gov' => [
            'patterns' => ['modern.gov', 'moderngov', 'committee manage', 'committee admin', 'committee service', 'agenda.*management', 'edemocracy', 'e-democracy', 'democratic content'],
            'capabilities' => ['workflow'],
            'areas' => ['democratic'],
            'desc' => 'Committee and democratic services management'
        ],
        'Open Revenues' => [
            'patterns' => ['revenue', 'benefit', 'council tax', 'ctax', 'single person discount'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['revenues'],
            'desc' => 'Revenues and benefits system'
        ],
        'Xpress' => [
            'patterns' => ['xpress', 'election', 'electoral', 'canvass', 'register of elector'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['democratic'],
            'desc' => 'Electoral management system'
        ],
        'APP/Flare' => [
            'patterns' => ['flare', 'regulatory', 'licensing.*case', 'environmental health', 'trading standard', 'civica app'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['licensing'],
            'desc' => 'Regulatory services and licensing case management'
        ],
        'Digital360' => [
            'patterns' => ['digital360', 'dm360', 'document manage'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['doc-records'],
            'desc' => 'Document management system'
        ],
        'Financials' => [
            'patterns' => ['financials', 'sundry debtor'],
            'capabilities' => ['payments'],
            'areas' => [],
            'corp' => ['financial'],
            'desc' => 'Financial management system'
        ],
        'ICON/CivicaPay' => [
            'patterns' => ['icon', 'civicapay', 'payment.*income', 'income.*management', 'income.*system'],
            'capabilities' => ['payments'],
            'areas' => ['revenues'],
            'desc' => 'Income management and payments'
        ],
        'Housing' => [
            'patterns' => ['housing', 'choice.*letting', 'homechoice'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Housing management and choice-based lettings'
        ],
        'Coroners' => [
            'patterns' => ['coroner'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['legal'],
            'desc' => 'Coroners case management'
        ],
        'Legal Case Management' => [
            'patterns' => ['legal.*case'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['legal'],
            'desc' => 'Legal case management'
        ],
        'Fleet/Tranman' => [
            'patterns' => ['fleet', 'tranman', 'transend'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['facilities'],
            'desc' => 'Fleet management'
        ],
        'CX Platform' => [
            'patterns' => ['civica cx'],
            'capabilities' => ['forms', 'workflow', 'payments'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Customer experience platform'
        ],
        'Eclipse' => [
            'patterns' => ['eclipse', 'olm.*system', 'olm social', 'eclipse.*social care', 'eclipse.*adult'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['adult-care'],
            'desc' => 'Adults social care case management (formerly OLM Systems)'
        ],
    ],

    'Idox Software' => [
        'Uniform' => [
            'patterns' => ['uniform', 'planning.*software', 'planning.*system', 'building control', 'land charge', 'acolaid'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['planning'],
            'desc' => 'Planning, building control, land charges, licensing and environmental health case management'
        ],
        'Elections (Eros)' => [
            'patterns' => ['electoral', 'election', 'eros'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['democratic'],
            'desc' => 'Electoral management'
        ],
        'EHC Hub' => [
            'patterns' => ['ehc', 'education.*health.*care'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['education', 'childrens'],
            'desc' => 'Education Health and Care plan management'
        ],
        'GrantFinder' => [
            'patterns' => ['grantfinder', 'grant.*finder'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['financial'],
            'desc' => 'Grant funding search tool'
        ],
        'Licensing (Lalpac)' => [
            'patterns' => ['lalpac', 'licensing.*system', 'taxi.*licen', 'business.*licen'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['licensing'],
            'desc' => 'Licensing case management (taxi, premises, etc.)'
        ],
        'Open Objects' => [
            'patterns' => ['open.*object', 'family.*information', 'directory', 'asklio'],
            'capabilities' => ['forms'],
            'areas' => ['childrens', 'education'],
            'desc' => 'Family information and local directory'
        ],
        'iManage' => [
            'patterns' => ['imanage'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['doc-records'],
            'desc' => 'Document and case management'
        ],
        'Cloud/Hosting' => [
            'patterns' => ['idox cloud', 'idox.*hosting'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['compute'],
            'desc' => 'Cloud hosting services'
        ],
        'HBSMR' => [
            'patterns' => ['hbsmr', 'historic.*environment', 'heritage'],
            'capabilities' => ['workflow'],
            'areas' => ['leisure'],
            'desc' => 'Historic buildings/sites/monuments records'
        ],
        'Exacom CIL' => [
            'patterns' => ['exacom', 'community infrastructure levy', 'cil'],
            'capabilities' => ['payments', 'workflow'],
            'areas' => ['planning'],
            'desc' => 'Community Infrastructure Levy management'
        ],
        'Countryside (CAMS)' => [
            'patterns' => ['countryside', 'cams'],
            'capabilities' => ['workflow'],
            'areas' => ['leisure'],
            'desc' => 'Countryside access management'
        ],
        'Uniform (Public Protection)' => [
            'patterns' => ['public protection', 'environmental health', 'trading standard', 'food hygiene', 'pollution'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['licensing'],
            'desc' => 'Environmental health and trading standards case management'
        ],
        'groundMapper' => [
            'patterns' => ['groundmapper', 'ground mapper'],
            'capabilities' => ['gis'],
            'areas' => [],
            'desc' => 'Cloud GIS platform'
        ],
        'CAFM Explorer' => [
            'patterns' => ['cafm', 'facilities.*management', 'helpdesk.*facilities'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['facilities'],
            'desc' => 'Computer-aided facilities management'
        ],
    ],

    'NEC Software Solutions' => [
        'NEC Revenues & Benefits' => [
            'patterns' => ['revenue', 'benefit', 'northgate.*revenue', 'council tax', 'housing benefit'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['revenues'],
            'desc' => 'Revenues and benefits platform'
        ],
        'Document Management' => [
            'patterns' => ['document', 'dms', 'docs online', 'scanning', 'mailroom'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['doc-records'],
            'desc' => 'Document management and scanning'
        ],
        'NEC Housing' => [
            'patterns' => ['housing'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Housing management system (formerly Northgate Housing)'
        ],
        'Blue Badge' => [
            'patterns' => ['blue badge'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['highways'],
            'desc' => 'Blue badge case management'
        ],
        'Youth Justice (M3)' => [
            'patterns' => ['youth.*offend', 'northgate m3'],
            'capabilities' => ['workflow'],
            'areas' => ['childrens'],
            'desc' => 'Youth offending case management'
        ],
        'Assure (Regulatory/Land Charges)' => [
            'patterns' => ['assure', 'm3.*assure', 'm3.*land', 'm3.*planning', 'm3.*building', 'm3.*env.*health', 'm3 land charge', 'northgate.*assure', 'northgate.*land charge'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['planning', 'licensing'],
            'desc' => 'Regulatory services, planning, building control and land charges (M3/Assure)'
        ],
        'Enforcement/Parking' => [
            'patterns' => ['enforcement', 'parking.*management', 'traffic.*management', 'pcn'],
            'capabilities' => ['workflow'],
            'areas' => ['highways'],
            'desc' => 'Parking and traffic enforcement'
        ],
        'Digital Services (Jadu)' => [
            'patterns' => ['jadu.*nec', 'nec.*digital', 'citizen.*access', 'self.*service.*portal'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Digital services and citizen self-service (Jadu by NEC)'
        ],
    ],

    'The Access Group' => [
        'Mosaic' => [
            'patterns' => ['mosaic', 'social care.*record', 'social care.*case', 'adult.*case.*management', 'care.*planning.*software'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['adult-care', 'childrens'],
            'desc' => 'Social care case management (adults and children)'
        ],
        'Pay360' => [
            'patterns' => ['pay360', 'e-payment', 'epayment', 'income.*management', 'cash.*receipt', 'crims', 'payment.*solution', 'electronic.*transaction'],
            'capabilities' => ['payments'],
            'areas' => ['revenues'],
            'desc' => 'Payments and income management'
        ],
        'Synergy' => [
            'patterns' => ['synergy', 'education.*management', 'education.*system', 'education.*solution'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['education'],
            'desc' => 'Education management system'
        ],
        'Core+' => [
            'patterns' => ['core\+', 'core plus', 'integrated youth', 'youth.*support.*system', 'youth.*justice'],
            'capabilities' => ['workflow'],
            'areas' => ['childrens'],
            'desc' => 'Children and young people case management'
        ],
        'PAMMS' => [
            'patterns' => ['pamms', 'provider.*assessment', 'market.*management'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['adult-care', 'childrens'],
            'desc' => 'Provider assessment and market management'
        ],
        'People Planner' => [
            'patterns' => ['people.*planner', 'rostering', 'care.*monitoring'],
            'capabilities' => ['workflow'],
            'areas' => ['adult-care'],
            'desc' => 'Care workforce rostering and scheduling'
        ],
        'Financials' => [
            'patterns' => ['access.*financial'],
            'capabilities' => ['payments'],
            'areas' => [],
            'corp' => ['financial'],
            'desc' => 'Financial management'
        ],
        'Elemental' => [
            'patterns' => ['elemental', 'social prescribing'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['public-health'],
            'desc' => 'Social prescribing platform'
        ],
    ],

    'Liquidlogic' => ['vendor' => 'System C',
        'Adults (LAS)' => [
            'patterns' => ['adult', 'las', 'social care'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['adult-care'],
            'desc' => 'Adults social care case management'
        ],
        'Childrens (LCS)' => [
            'patterns' => ['child', 'lcs', 'early help'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['childrens'],
            'desc' => 'Childrens social care case management'
        ],
        'ContrOCC' => [
            'patterns' => ['controcc', 'finance'],
            'capabilities' => ['payments', 'workflow'],
            'areas' => ['adult-care', 'childrens'],
            'desc' => 'Social care financial management'
        ],
        'SCF / Abacus' => [
            'patterns' => ['scf', 'abacus', 'social care finance', 'liquidlogic.*finance', 'care.*financial.*management'],
            'capabilities' => ['payments', 'workflow'],
            'areas' => ['adult-care'],
            'desc' => 'Adults social care financial management (formerly Abacus)'
        ],
        'Commissioning' => [
            'patterns' => ['commission', 'provider.*portal', 'contract.*management.*social care', 'brokerage'],
            'capabilities' => ['workflow'],
            'areas' => ['adult-care'],
            'desc' => 'Adult social care commissioning and provider management'
        ],
        'EYES (Education)' => [
            'patterns' => ['eyes', 'education.*case.*management', 'trafford early years', 'send.*case.*management', 'education.*system.*send'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['education', 'childrens'],
            'desc' => 'Education case management (SEND, EHC, early years) — EYES product'
        ],
    ],

'System C Healthcare' => [
        'CareFirst' => [
            'patterns' => ['carefirst', 'care first', 'system c', 'social care'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['adult-care', 'childrens'],
            'desc' => 'Social care case management'
        ],
    ],
    'Granicus-Firmstep' => [
        'Digital Platform' => [
            'patterns' => ['.*'],
            'capabilities' => ['forms', 'workflow', 'payments', 'identity'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Customer digital experience platform (forms, workflow, payments, CRM)'
        ],
    ],

    'Netcall' => [
        'Liberty Platform' => [
            'patterns' => ['digital platform', 'lowcode', 'low.*code', 'tenant.*hub', 'customer.*engage', 'webcapture'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Low-code digital platform and customer engagement'
        ],
        'Contact Centre' => [
            'patterns' => ['contact.*centre', 'call.*routing', 'ivr', 'queue.*manage', 'telephony'],
            'capabilities' => ['telephony'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Contact centre and telephony'
        ],
        'RPA' => [
            'patterns' => ['robotic', 'rpa', 'automation'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['intelligent-auto'],
            'desc' => 'Robotic process automation'
        ],
    ],

    'Capita' => [
        'Capita One' => [
            'patterns' => ['capita one', 'one.*education', 'education.*management', 'school.*admission'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['education'],
            'desc' => 'Education management system'
        ],
        'Pay360' => [
            'patterns' => ['pay360', 'income.*management', 'income.*software', 'payment.*service'],
            'capabilities' => ['payments'],
            'areas' => ['revenues'],
            'desc' => 'Payment and income management'
        ],
        'Open Housing' => [
            'patterns' => ['housing', 'open.*housing'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Social housing management (Open Housing)'
        ],
        'Academy' => [
            'patterns' => ['revenue', 'benefit', 'academy.*capita', 'council tax', 'housing benefit'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['revenues'],
            'desc' => 'Council tax, NNDR and housing benefit (Academy)'
        ],
        'Library' => [
            'patterns' => ['library'],
            'capabilities' => ['booking'],
            'areas' => ['leisure'],
            'desc' => 'Library management system'
        ],
        'Contact Centre' => [
            'patterns' => ['contact centre', 'customer service', 'capita.*contact'],
            'capabilities' => ['telephony'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Contact centre services'
        ],
        'Parking (PARIS)' => [
            'patterns' => ['paris', 'capita.*parking', 'parking.*solution'],
            'capabilities' => ['payments'],
            'areas' => ['highways'],
            'desc' => 'Parking management and enforcement'
        ],
    ],


    'Bottomline Technologies' => [
        'PTX' => [
            'patterns' => ['ptx', 'bacs', 'faster payment', 'direct debit', 'account validation', 'payment.*bureau'],
            'capabilities' => ['payments'],
            'areas' => [],
            'corp' => ['financial'],
            'desc' => 'Bacs, Faster Payments and direct debit processing'
        ],
    ],

    'Quadient' => [
        'Impress' => [
            'patterns' => ['impress', 'outbound.*comm', 'customer.*comm', 'mailstream', 'postal.*optimizer'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['doc-records'],
            'desc' => 'Multi-channel outbound communications (post, email, SMS)'
        ],
        'AP Automation' => [
            'patterns' => ['accounts payable', 'ap automation', 'invoice.*automation'],
            'capabilities' => ['payments'],
            'areas' => [],
            'corp' => ['financial', 'procurement'],
            'desc' => 'Accounts payable automation'
        ],
    ],

    // Supplier canon MUST match how rows are actually canonicalised (pattern
    // 584 -> "Beam Notes (formerly Magic Notes)"); the bare "Beam Notes" key
    // matched 0 entries. Title patterns identify the genuine AI note-taking
    // product and deliberately AVOID the homelessness charity "Beam" (Beam Up
    // Ltd trades under the same canon) — housing/employment/accommodation
    // contracts must NOT link to this product.
    'Beam Notes (formerly Magic Notes)' => [
        'Magic Notes' => [
            'patterns' => ['magic.*notes', 'beam notes', 'beam.*magic', 'magic.*beam', 'ai.*note', 'note.*taking', 'transcription', 'summaris', 'summariz', 'assessment.*soft', 'assessment solution', 'recording tool', 'dictation', 'case management automation', 'caseworker', 'case note', 'casework.*automat', 'ai.*social care'],
            'exclude' => ['homeless', 'temporary accommodation', 'private rented', 'floating support', 'employment', 'refugee', 'housing.*support', 'housing first', 'settled housing', 'accommodation based', 'casework service', 'beam sla', 'beam casework', 'beam poc', 'beam housing', 'beam - ta', 'beam up ltd to support', 'housing assist', 'pathway', 'families from', 'ta support'],
            'capabilities' => ['workflow'],
            'areas' => ['adult-care', 'childrens'],
            'desc' => 'AI-assisted note-taking and assessment for social care'
        ],
    ],

    'Locata' => [
        'LocataPro' => [
            'patterns' => ['locata', 'housing register', 'choice.*based.*letting', 'homelessness.*system', 'temp.*accommodation.*system'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Housing register, CBL allocations and homelessness management'
        ],
    ],

    'IEG4' => [
        'Open4' => [
            'patterns' => ['ieg4', 'open4', 'low.*code.*form', 'eform', 'e-form', 'myaccount', 'citizen.*portal'],
            'capabilities' => ['forms', 'payments', 'workflow', 'identity'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Low-code digital services platform (Open4)'
        ],
        'Blue Badge / Travel' => [
            'patterns' => ['blue badge.*ieg', 'freedom pass', 'taxi card', 'concessionary travel'],
            'capabilities' => ['forms'],
            'areas' => ['adult-care', 'highways'],
            'desc' => 'Blue badge and concessionary travel digital services'
        ],
        'APAS / Swiftsearch (Built Environment)' => [
            'patterns' => ['apas', 'swiftsearch', 'agile.*planning', 'agile.*building control', 'agile.*land charge', 'built environment.*agile', 'agile.*built environment'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['planning'],
            'desc' => 'Planning, building control and land charges case management (Agile APAS/Swiftsearch by IEG4)'
        ],
    ],

    'Flowbird' => [
        'Parking' => [
            'patterns' => ['flowbird', 'parking.*management', 'pay.*plate', 'on.*street.*parking', 'kerb.*manage'],
            'capabilities' => ['payments'],
            'areas' => ['highways'],
            'desc' => 'Parking management and analytics'
        ],
    ],

    'Policy in Practice' => [
        'LIFT' => [
            'patterns' => ['lift', 'low income.*tracker', 'income maximis', 'benefit.*calculator', 'better off.*calculator', 'council tax reduction.*model'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['revenues'],
            'desc' => 'Benefits analytics and income maximisation (LIFT)'
        ],
        'Safeguarding Tracker' => [
            'patterns' => ['safeguarding.*tracker', 'mast', 'multi.*agency.*safeguard'],
            'capabilities' => ['workflow'],
            'areas' => ['adult-care', 'childrens'],
            'desc' => 'Multi-agency safeguarding data tracker'
        ],
    ],

    'Jadu' => [
        'Jadu Central' => [
            'patterns' => ['jadu central', 'jadu.*cms', 'jadu.*form', 'jadu.*web', '^jadu$'],
            'capabilities' => ['forms', 'payments', 'workflow', 'identity'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Website CMS and digital forms platform'
        ],
        'Jadu Connect' => [
            'patterns' => ['jadu connect', 'jadu.*case', 'jadu.*crm'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Customer case management and digital experience'
        ],
    ],

    'TotalMobile' => [
        'Environmental Services' => [
            'patterns' => ['totalmobile.*waste', 'totalmobile.*environment', 'waste.*scheduling', 'waste.*round'],
            'capabilities' => ['workflow'],
            'areas' => ['waste'],
            'desc' => 'Waste and environmental services field workforce management'
        ],
        'Housing Repairs' => [
            'patterns' => ['totalmobile.*housing', 'housing.*repair', 'repairs.*maintenance.*mobile'],
            'capabilities' => ['workflow'],
            'areas' => ['housing'],
            'desc' => 'Housing repairs and maintenance field management'
        ],
        'Care at Home' => [
            'patterns' => ['totalmobile.*care', 'reablement', 'care.*home.*scheduling', 'care.*rostering'],
            'capabilities' => ['workflow'],
            'areas' => ['adult-care'],
            'desc' => 'Care-at-home rostering and scheduling'
        ],
    ],

    'Arcus Global' => [
        'Planning & Regulatory' => [
            'patterns' => ['arcus.*planning', 'arcus.*building control', 'arcus.*land charge', 'arcus.*enforcement', 'arcus.*regulatory', 'arcus.*env.*health', 'arcus.*food'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['planning', 'licensing'],
            'desc' => 'Salesforce-based planning, building control and regulatory services'
        ],
        'Licensing' => [
            'patterns' => ['arcus.*licens', 'arcus.*taxi', 'arcus.*hmo', 'arcus.*selective'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['licensing'],
            'desc' => 'Licensing case management (taxi, HMO, premises)'
        ],
        'Housing' => [
            'patterns' => ['arcus.*housing', 'arcus.*hhsrs', 'arcus.*private.*sector', 'arcus.*disabled.*facilities', 'arcus.*anti.*social'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Private sector housing and housing enforcement'
        ],
        'Waste' => [
            'patterns' => ['arcus.*waste', 'arcus.*pest'],
            'capabilities' => ['workflow'],
            'areas' => ['waste'],
            'desc' => 'Waste management and pest control'
        ],
        'CRM' => [
            'patterns' => ['arcus.*crm', 'arcus.*salesforce', 'arcus.*report it', 'arcus.*staff hub'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Salesforce-based CRM and citizen reporting'
        ],
        'GIS/Gazetteer' => [
            'patterns' => ['arcus.*gazetteer', 'arcus.*gis', 'arcus.*land.*property'],
            'capabilities' => ['gis'],
            'areas' => [],
            'desc' => 'Land, property and gazetteer management'
        ],
    ],

    'DEF Software' => [
        'MasterGov Planning' => [
            'patterns' => ['mastergov', 'def.*planning', 'def.*building control', 'def.*land charge', 'def.*enforcement', 'def.*s106', 'def.*tree preservation', 'def.*listed building'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['planning'],
            'desc' => 'Planning, building control and land charges case management'
        ],
        'MasterGov Highways' => [
            'patterns' => ['def.*highway', 'def.*road adoption', 'def.*land drainage', 'def.*travel plan'],
            'capabilities' => ['workflow'],
            'areas' => ['highways', 'planning'],
            'desc' => 'Highways and infrastructure planning management'
        ],
    ],
    'Allpay' => [
        'Payments & Prepaid Cards' => [
            'patterns' => ['.*'],
            'capabilities' => ['payments'],
            'areas' => ['revenues', 'adult-care'],
            'desc' => 'Bill payments, prepaid cards and direct payment management'
        ],
    ],

    'CACI' => [
        'Childview' => [
            'patterns' => ['childview', 'youth.*justice', 'youth.*offend'],
            'capabilities' => ['workflow'],
            'areas' => ['childrens'],
            'desc' => 'Youth justice case management'
        ],
        'Cygnum' => [
            'patterns' => ['cygnum'],
            'capabilities' => ['workflow'],
            'areas' => ['adult-care'],
            'desc' => 'Social care scheduling'
        ],
        'Acorn/Paycheck' => [
            'patterns' => ['acorn', 'paycheck', 'segmentation', 'geo-demographic'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['bi-analytics'],
            'desc' => 'Geodemographic and market segmentation data'
        ],
        'EHC' => [
            'patterns' => ['ehc', 'statutory.*education'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['education', 'childrens'],
            'desc' => 'Education Health Care plan management'
        ],
        'Impulse (Education)' => [
            'patterns' => ['impulse.*nexus', 'impulse.*educ', 'impulse.*school', 'impulse.*admission', 'nexus.*educ', 'nexus.*school'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['education', 'childrens'],
            'desc' => 'Education management — school admissions, SEN, exclusions (Impulse Nexus)'
        ],
    ],

    'Bibliotheca' => [
        'Self-Service & RFID' => [
            'patterns' => ['.*'],
            'capabilities' => ['booking'],
            'areas' => ['leisure'],
            'desc' => 'Library self-service kiosks and RFID systems'
        ],
    ],

    'Bartec' => [
        'Collective (Waste)' => [
            'patterns' => ['.*'],
            'capabilities' => ['workflow'],
            'areas' => ['waste'],
            'desc' => 'Waste management, in-cab technology, and route planning'
        ],
    ],

    'Tunstall Healthcare' => [
        'Telecare' => [
            'patterns' => ['.*'],
            'capabilities' => ['workflow'],
            'areas' => ['adult-care'],
            'desc' => 'Telecare monitoring and assistive technology'
        ],
    ],

    'Esri UK' => [
        'ArcGIS' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['geo'],
            'data' => ['geospatial'],
            'desc' => 'Geographic information system and mapping platform'
        ],
    ],

    'MRI Software' => [
        'Housing' => [
            'patterns' => ['housing', 'homeless', 'homeswapper', 'prevention.*relief', 'mutual exchange'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Housing management and homelessness'
        ],
        'CAFM/Property' => [
            'patterns' => ['cafm', 'facilit', 'property', 'lease', 'qube'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['facilities'],
            'desc' => 'Facilities and property management'
        ],
    ],

    'Causeway Technologies' => [
        'Highways/Streetworks' => [
            'patterns' => ['highway', 'streetwork', 'street.*light', 'traffic.*order', 'tro', 'one.*network', 'parkmap', 'road'],
            'capabilities' => ['workflow'],
            'areas' => ['highways'],
            'desc' => 'Highways asset management and traffic orders'
        ],
        'Alloy (Asset Management)' => [
            'patterns' => ['alloy', 'asset.*manage', 'waste.*manage', 'tree'],
            'capabilities' => ['workflow'],
            'areas' => ['waste', 'highways'],
            'desc' => 'Street-based asset management'
        ],
    ],

    'Public-i' => [
        'Webcasting' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => ['democratic'],
            'desc' => 'Council meeting webcasting and streaming'
        ],
    ],

    'Softcat' => [
        'IT Reseller' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['sw-asset'],
            'desc' => 'IT reseller — software licensing and hardware'
        ],
    ],

    'Phoenix Software' => [
        'IT Reseller' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['sw-asset'],
            'desc' => 'IT reseller — Microsoft licensing specialist'
        ],
    ],

    'Insight Direct' => [
        'IT Reseller' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['sw-asset'],
            'desc' => 'IT reseller — hardware and software'
        ],
    ],

    'CDW' => [
        'IT Reseller' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['sw-asset'],
            'desc' => 'IT reseller — infrastructure and cloud'
        ],
    ],

    'Bytes' => [
        'IT Reseller' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['sw-asset'],
            'desc' => 'IT reseller — software licensing'
        ],
    ],

    'XMA' => [
        'IT Reseller' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['sw-asset', 'end-user-devices'],
            'desc' => 'IT reseller — end user devices and software'
        ],
    ],

    'Boxxe' => [
        'IT Reseller' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['sw-asset'],
            'desc' => 'IT reseller — software licensing'
        ],
    ],

    'BT' => [
        'Network Services' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['connectivity'],
            'desc' => 'Network connectivity, WAN, and telephony'
        ],
    ],

    'Virgin Media Business' => [
        'Network Services' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['connectivity'],
            'desc' => 'Network connectivity and broadband'
        ],
    ],

    'Vodafone' => [
        'Mobile & Network' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['connectivity', 'end-user-devices'],
            'desc' => 'Mobile services and network connectivity'
        ],
    ],

    'Microsoft' => [
        'M365/Azure' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['productivity', 'compute', 'unified-comms'],
            'desc' => 'Microsoft 365, Azure cloud, and productivity suite'
        ],
    ],

    'Oracle' => [
        'Enterprise Applications' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['financial', 'hr'],
            'desc' => 'ERP, financials and HR'
        ],
    ],

    'Thomson Reuters' => [
        'Practical Law/Westlaw' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['legal'],
            'desc' => 'Legal research and reference'
        ],
    ],

    'Vivacity Labs' => [
        'Traffic Sensors' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => ['highways'],
            'data' => ['data-science'],
            'desc' => 'AI-powered traffic sensors and analytics'
        ],
    ],

    'Tri.X' => [
        'Procedures Manual' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => ['childrens', 'adult-care'],
            'found' => ['knowledge-mgmt'],
            'desc' => 'Online procedures manual for social care'
        ],
    ],

    'Learning Pool' => [
        'eLearning' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['hr'],
            'desc' => 'eLearning and training platform'
        ],
    ],

    'Symology' => [
        'Highways/Streetworks' => [
            'patterns' => ['.*'],
            'capabilities' => ['workflow'],
            'areas' => ['highways'],
            'desc' => 'Highways and streetworks management'
        ],
    ],

    'Ideagen' => [
        'GRC Platform' => [
            'patterns' => ['.*'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['governance'],
            'desc' => 'Governance, risk, and compliance'
        ],
    ],

    'Oxygen Finance' => [
        'Early Payment' => [
            'patterns' => ['.*'],
            'capabilities' => ['payments'],
            'areas' => [],
            'corp' => ['financial'],
            'desc' => 'Supplier early payment and dynamic discounting'
        ],
    ],

    'Orlo' => [
        'Social Media Management' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['comms'],
            'desc' => 'Social media management platform'
        ],
    ],

    'Alcium Software' => [
        'Income Management' => [
            'patterns' => ['.*'],
            'capabilities' => ['payments'],
            'areas' => ['revenues'],
            'desc' => 'Income management and e-payments'
        ],
    ],

    'Nominet' => [
        'Domain Services' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['connectivity'],
            'desc' => 'Domain name registration and DNS services'
        ],
    ],

    'Iken Business' => [
        'Legal Case Management' => [
            'patterns' => ['.*'],
            'capabilities' => ['workflow'],
            'areas' => [],
            'corp' => ['legal'],
            'desc' => 'Legal practice and case management'
        ],
    ],

    'Pitney Bowes' => [
        'Mail & Location' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['facilities'],
            'data' => ['geospatial'],
            'desc' => 'Mail processing and location intelligence'
        ],
    ],

    'Unit4' => [
        'ERP/Financials' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['financial', 'hr'],
            'desc' => 'ERP — financials, HR and payroll'
        ],
    ],

    'Advanced Business Solutions' => [
        'OpenAccounts/HR' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['financial', 'hr'],
            'desc' => 'Financial management and HR systems'
        ],
    ],

    'Ricoh' => [
        'Print & Document' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['facilities'],
            'data' => ['doc-records'],
            'desc' => 'Managed print services and document solutions'
        ],
    ],

    'Brightly Software' => [
        'Confirm' => [
            'patterns' => ['.*'],
            'capabilities' => ['workflow'],
            'areas' => ['highways'],
            'corp' => ['facilities'],
            'desc' => 'Highways and infrastructure asset management (Confirm)'
        ],
    ],

    'Fiscal Technologies' => [
        'AP Forensics' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'corp' => ['financial'],
            'desc' => 'Accounts payable forensics and duplicate detection'
        ],
    ],

    'SCC' => [
        'IT Services' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => [],
            'found' => ['sw-asset', 'compute'],
            'desc' => 'IT services, licensing and infrastructure'
        ],
    ],

    'Trapeze Group' => [
        'Transport' => [
            'patterns' => ['.*'],
            'capabilities' => ['workflow', 'booking'],
            'areas' => ['highways'],
            'desc' => 'Passenger transport management'
        ],
    ],

    'Yunex Traffic' => [
        'Traffic Management' => [
            'patterns' => ['.*'],
            'capabilities' => [],
            'areas' => ['highways'],
            'desc' => 'Traffic signal control and UTC systems'
        ],
    ],

    'Experian' => [
        'Data & Identity' => [
            'patterns' => ['.*'],
            'capabilities' => ['identity'],
            'areas' => ['revenues'],
            'data' => ['data-science'],
            'desc' => 'Identity verification, credit data, and fraud prevention'
        ],
    ],

    'MRI Community Software' => [
        'Revenues & Benefits' => [
            'patterns' => ['revenue', 'benefit', 'council tax', 'mri.*revenue', 'mri.*benefit'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['revenues'],
            'desc' => 'Revenues and benefits platform (MRI, formerly Capita/NEC)'
        ],
        'Education (One/Synergy)' => [
            'patterns' => ['education', 'one cloud', 'capita one', 'admission', 'e-start', 'estart'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['education', 'childrens'],
            'desc' => 'Education management system (One Cloud, formerly Capita One)'
        ],
        'Homelessness' => [
            'patterns' => ['homeless', 'homelessness reduction', 'housing.*reduction'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Homelessness reduction case management'
        ],
    ],

    'MRI Housing Partners' => [
        'Jigsaw (Homelessness)' => [
            'patterns' => ['jigsaw', 'housing partners', 'prah', 'homeswapper', 'home swapper', 'mutual exchange'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Homelessness management and mutual exchange (Jigsaw/Homeswapper)'
        ],
    ],

    'Orchard Information Systems' => [
        'Housing' => [
            'patterns' => ['orchard.*housing', 'orchard.*software', 'orchard.*management', 'orchard.*system'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['housing'],
            'desc' => 'Social housing management (Orchard, now MRI)'
        ],
    ],

    'Whitespace Work Software' => [
        'Waste Management' => [
            'patterns' => ['whitespace'],
            'capabilities' => ['workflow'],
            'areas' => ['waste'],
            'desc' => 'Waste and recycling collections management'
        ],
    ],

    'Democracy Counts' => [
        'Elector8 (Electoral)' => [
            'patterns' => ['elector8', 'democracy counts'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['democratic'],
            'desc' => 'Electoral management system (Elector8)'
        ],
    ],

    'MODERN DEMOCRACY' => [
        'Modern Polling' => [
            'patterns' => ['modern.*poll', 'modern democracy', 'polling station.*software', 'digital.*poll', 'voter.*id.*poll'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['democratic'],
            'desc' => 'Digital polling station software and e-register'
        ],
    ],

    'Ocella' => [
        'Planning & Regulatory' => [
            'patterns' => ['ocella'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['planning', 'licensing'],
            'desc' => 'Planning, building control, land charges and environmental health case management'
        ],
    ],

    'Tribal' => [
        'EBS (Adult Learning)' => [
            'patterns' => ['tribal.*ebs', 'tribal.*mi', 'tribal.*mis', 'ebs.*management information', 'tribal.*adult.*learn'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['education'],
            'desc' => 'Adult and community learning management information system (EBS)'
        ],
    ],

    'Abavus' => [
        'My Council Services' => [
            'patterns' => ['abavus', 'my council services'],
            'capabilities' => ['forms', 'workflow', 'payments'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Digital customer services platform (My Council Services)'
        ],
    ],

    'Goss Interactive' => [
        'Digital Platform' => [
            'patterns' => ['goss.*digital', 'goss.*platform', 'goss.*selfserve', 'goss.*self serve', 'goss.*crm', 'goss.*cms', 'goss.*web', 'goss.*form'],
            'capabilities' => ['forms', 'workflow', 'payments'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Digital customer experience and CRM platform'
        ],
    ],

    'Verint Systems' => [
        'CRM / Lagan' => [
            'patterns' => ['verint', 'lagan'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'CRM and customer engagement platform (Lagan by Verint)'
        ],
    ],

    'Salesforce' => [
        'CRM Platform' => [
            'patterns' => ['salesforce'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'CRM and digital experience platform'
        ],
    ],

    'Placecube' => [
        'Digital Place' => [
            'patterns' => ['placecube', 'digital place.*public service', 'open place directory'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => [],
            'corp' => ['crm'],
            'desc' => 'Open-source digital services and directory platform (Digital Place)'
        ],
    ],

    'StatMap' => [
        'Aurora/eVO (Gazetteer & GIS)' => [
            'patterns' => ['statmap', 'earthlight', 'aurora.*statmap', 'evo.*gms', 'evo.*tro', 'evo.*statmap'],
            'capabilities' => ['gis'],
            'areas' => [],
            'corp' => ['geo'],
            'desc' => 'Gazetteer management, GIS and traffic regulation order software'
        ],
        'Horizon (Planning/Building Control)' => [
            'patterns' => ['statmap.*horizon', 'horizon.*planning', 'horizon.*building control', 'horizon.*land charge'],
            'capabilities' => ['forms', 'workflow'],
            'areas' => ['planning'],
            'desc' => 'Planning, building control and land charges case management (Horizon)'
        ],
    ],

    'Webaspx' => [
        'Waste Management' => [
            'patterns' => ['webaspx', 'easyroute'],
            'capabilities' => ['workflow'],
            'areas' => ['waste'],
            'desc' => 'Waste management routing and in-cab technology'
        ],
    ],
];

// ─── PROCESS ──────────────────────────────────────────────────────────────

// Get company numbers for suppliers
$ch_stmt = $pdo->prepare("SELECT company_number FROM ct_suppliers WHERE canonical_name = ?");

$insert_product = $pdo->prepare("
    INSERT INTO ct_lgam_products (supplier_canon, company_number, product_name, display_name, description, vendor)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE description = VALUES(description), display_name = VALUES(display_name), vendor = VALUES(vendor)
");

$insert_cap = $pdo->prepare("INSERT IGNORE INTO ct_lgam_product_capabilities (product_id, node_id) VALUES (?, ?)");
$insert_area = $pdo->prepare("INSERT IGNORE INTO ct_lgam_product_areas (product_id, node_id) VALUES (?, ?)");
$insert_link = $pdo->prepare("INSERT IGNORE INTO ct_lgam_entry_products (entry_id, product_id) VALUES (?, ?)");

$stats = ['products' => 0, 'caps' => 0, 'areas' => 0, 'links' => 0];

foreach ($PRODUCTS as $supplier => $products) {
    // Look up CH number
    $ch_stmt->execute([$supplier]);
    $company_number = $ch_stmt->fetchColumn() ?: null;

    // Get all entries for this supplier
    $entries_stmt = $pdo->prepare("SELECT id, contract_title FROM ct_contract_register_entries WHERE supplier_canon = ? AND is_tech = 1");
    $entries_stmt->execute([$supplier]);
    $entries = $entries_stmt->fetchAll();

    foreach ($products as $product_name => $def) {
        if ($product_name === "vendor") continue; // metadata key, not a product
        $vendor = $products['vendor'] ?? null;
        $display = $vendor ? $supplier . ' ' . $product_name : $product_name;
        $insert_product->execute([$supplier, $company_number, $product_name, $display, $def['desc'], $vendor]);
        $product_id = $pdo->lastInsertId();
        if (!$product_id) {
            // Already existed, get ID
            $pid_stmt = $pdo->prepare("SELECT id FROM ct_lgam_products WHERE supplier_canon = ? AND product_name = ?");
            $pid_stmt->execute([$supplier, $product_name]);
            $product_id = $pid_stmt->fetchColumn();
        }
        $stats['products']++;

        // Insert capabilities
        foreach ($def['capabilities'] as $cap) {
            $insert_cap->execute([$product_id, $cap]);
            $stats['caps']++;
        }

        // Insert business areas (l3)
        foreach ($def['areas'] as $area) {
            $insert_area->execute([$product_id, $area]);
            $stats['areas']++;
        }

        // Insert corporate areas (l4) — same table, different node IDs
        foreach (($def['corp'] ?? []) as $corp) {
            $insert_area->execute([$product_id, $corp]);
            $stats['areas']++;
        }

        // Insert foundational (l5a-e)
        foreach (($def['found'] ?? []) as $f) {
            $insert_area->execute([$product_id, $f]);
            $stats['areas']++;
        }

        // Insert data/info (l8)
        foreach (($def['data'] ?? []) as $d) {
            $insert_area->execute([$product_id, $d]);
            $stats['areas']++;
        }

        // Match entries to this product
        foreach ($entries as $entry) {
            $title = strtolower($entry['contract_title'] ?? '');
            // Optional exclude patterns: skip titles that look like a different
            // offering from the same canonical supplier (e.g. the homelessness
            // charity "Beam" vs Beam Up Ltd's Magic Notes AI product).
            $excluded = false;
            foreach (($def['exclude'] ?? []) as $ex) {
                if (preg_match('/' . $ex . '/i', $title)) { $excluded = true; break; }
            }
            if ($excluded) continue;
            foreach ($def['patterns'] as $pattern) {
                if (preg_match('/' . $pattern . '/i', $title)) {
                    $insert_link->execute([$entry['id'], $product_id]);
                    $stats['links']++;
                    break; // Only link once per product per entry
                }
            }
        }
    }

    echo sprintf("  %s: %d entries, %d products\n", $supplier, count($entries), count($products));
}

echo "\n=== Summary ===\n";
echo sprintf("Products created: %d\n", $stats['products']);
echo sprintf("Capability mappings: %d\n", $stats['caps']);
echo sprintf("Area mappings: %d\n", $stats['areas']);
echo sprintf("Entry-product links: %d\n", $stats['links']);

// Verify
$counts = $pdo->query("SELECT 
    (SELECT COUNT(*) FROM ct_lgam_products) as products,
    (SELECT COUNT(*) FROM ct_lgam_product_capabilities) as caps,
    (SELECT COUNT(*) FROM ct_lgam_product_areas) as areas,
    (SELECT COUNT(*) FROM ct_lgam_entry_products) as links
")->fetch();
echo sprintf("\nIn database: %d products, %d capabilities, %d areas, %d entry links\n",
    $counts['products'], $counts['caps'], $counts['areas'], $counts['links']);
