// src/i18n/symptomInfo.js
// WHO/IDSR-sourced symptom information for the secondary-screening info modal.
// All clinical text is sourced from existing Diseases.js entries
// (key_distinguishers, hallmarks, immediate_actions) and WHO IHR / IDSR
// guidelines. DO NOT invent clinical text — every entry's `definition`,
// `clinical_signs`, `differentiation`, `progression`, and `who_source` must
// be drawn from the cited sources or omitted. Where a sourced sentence is
// unavailable, omit that field.

export const SYMPTOM_INFO_BUNDLE = {
  fever: {
    definition:
      'A measured axillary temperature ≥ 37.5 °C (or ≥ 38 °C in most IDSR case definitions) is the cardinal sign of acute febrile illness. Sudden-onset fever is a gating signal for nearly every IHR-notifiable syndrome (VHF, malaria, meningitis, plague, COVID-19, influenza, dengue, typhoid, leptospirosis).',
    clinical_signs: [
      'Axillary temperature ≥ 37.5 °C (low-grade) or ≥ 39 °C (high fever)',
      'Chills / rigors and sweating cycle',
      'Tachycardia proportional to fever (relative bradycardia in yellow fever and rickettsiosis = Faget sign)',
      'Headache, myalgia, malaise as common accompaniments',
      'In children: irritability, poor feeding, lethargy',
    ],
    differentiation:
      'Pattern matters: cyclical 48 h / 72 h fever suggests malaria (P. vivax / P. malariae); biphasic fever with a remission then a renewed febrile-jaundice toxic phase is classical for yellow fever; sustained step-ladder fever ≥ 3 days with no clear focus suggests typhoid; sudden-onset high fever plus haemorrhagic signs raises immediate VHF concern; gradual insidious fever with sore throat suggests Lassa fever.',
    who_source:
      'WHO IDSR Technical Guidelines 2021 (3rd Edition); WHO IHR (2005) Annex 2; WHO Disease Outbreak News.',
  },

  cough: {
    definition:
      'Acute cough, with or without sputum, is the entry criterion for SARI (Severe Acute Respiratory Infection) and ILI (Influenza-Like Illness) surveillance. In a febrile traveller it gates SARS, MERS-CoV, novel/zoonotic influenza, COVID-19, pneumonic plague and pulmonary anthrax.',
    clinical_signs: [
      'Dry or productive cough of acute onset',
      'Sputum: clear (viral), purulent (bacterial), blood-tinged / frankly bloody (haemoptysis — pneumonic plague, TB)',
      'Frequently associated with fever, sore throat and shortness of breath',
      'Tachypnoea or chest pain when lower respiratory tract is involved',
      'In children: chest indrawing or stridor signal severe pneumonia',
    ],
    differentiation:
      'Cough plus shortness of breath plus travel from the Arabian Peninsula or camel exposure within 14 days = MERS-CoV until excluded. Cough plus haemoptysis plus rodent/flea exposure in an endemic area = pneumonic plague until excluded. Cough plus loss of taste/smell points to COVID-19. Cough with coryza, conjunctivitis and a maculopapular rash is the measles "3 Cs" triad. Absence of rhinitis (coryza) in severe febrile pneumonia is typical for SARS.',
    who_source:
      'WHO Global Influenza Surveillance Standards 2014 (SARI/ILI); WHO IDSR Technical Guidelines 2021 §8 (Acute Respiratory Syndrome); WHO IHR (2005) Annex 2.',
  },

  bleeding: {
    definition:
      'Unexplained bleeding from any site (gums, nose, gastrointestinal tract, genitourinary tract, injection sites, skin) in a febrile patient meets the WHO acute haemorrhagic fever syndrome case definition and triggers the VHF protocol.',
    clinical_signs: [
      'Bleeding gums or epistaxis (nose bleed)',
      'Haematemesis, melaena or haematochezia',
      'Haematuria or vaginal bleeding outside menses',
      'Petechiae, purpura, widespread ecchymosis or oozing from venepuncture sites',
      'Bloody sputum / haemoptysis',
      'Conjunctival haemorrhage',
    ],
    differentiation:
      'In a febrile traveller, unexplained haemorrhage from any site triggers the WHO Acute Haemorrhagic Fever Syndrome alert and the VHF protocol (Ebola, Marburg, Lassa, CCHF, Rift Valley fever, severe dengue, yellow fever toxic phase). Tick bite or livestock-slaughter exposure with rapid haemorrhage = CCHF until excluded. Plasma-leakage shock with thrombocytopenia after fever defervescence = severe dengue. Jaundice plus bleeding plus renewed fever after a brief remission = yellow fever toxic phase. Petechial / purpuric rash with sudden fever and stiff neck = meningococcaemia.',
    who_source:
      'WHO IDSR Technical Guidelines 2021 §8 (Acute Haemorrhagic Fever Syndrome); WHO IHR (2005) Annex 2; WHO VHF Clinical Management 2016.',
  },

  jaundice: {
    definition:
      'Yellow discolouration of the sclerae or skin, often with dark (tea-coloured) urine, indicates hyperbilirubinaemia. In a febrile patient it defines the WHO Acute Jaundice Syndrome and gates yellow fever, viral hepatitis (A/E), leptospirosis, severe malaria and the toxic phase of VHFs.',
    clinical_signs: [
      'Yellow tint of the sclerae (best seen in daylight)',
      'Yellow tint of the skin and mucous membranes',
      'Dark / tea-coloured urine',
      'Pale stools',
      'Loss of appetite, nausea, right-upper-quadrant tenderness',
      'Pruritus in cholestatic presentations',
    ],
    differentiation:
      'Acute fever followed by jaundice within 14 days in a yellow-fever-risk area or with travel to an endemic area meets the WHO yellow fever suspect case definition. Jaundice plus pruritus plus dark urine following flooding or in a displaced population suggests hepatitis E. Jaundice with conjunctival suffusion, calf-muscle tenderness and water/animal exposure within 30 days = leptospirosis. Jaundice in severe malaria (P. falciparum) is accompanied by haemoglobinuria (blackwater fever) and impaired consciousness.',
    who_source:
      'WHO IDSR Technical Guidelines 2021 §8 (Acute Jaundice Syndrome); WHO Yellow Fever Surveillance Standards 2018; WHO IHR (2005) Annex 2.',
  },

  rash_maculopapular: {
    definition:
      'A flat-or-slightly-raised, non-vesicular eruption of red macules and papules that blanch on pressure. In a febrile traveller it gates measles, rubella, dengue, Zika, chikungunya, secondary syphilis and rickettsial spotted fevers.',
    clinical_signs: [
      'Flat (macular) or slightly raised (papular) red lesions',
      'Lesions blanch when pressed',
      'Often confluent on the trunk',
      'May involve palms and soles in rickettsial infections and secondary syphilis',
      'Frequently preceded or accompanied by fever',
      'Post-auricular or sub-occipital lymphadenopathy in rubella',
    ],
    differentiation:
      'Fever + generalised maculopapular rash lasting ≥ 3 days + at least one of cough, coryza or conjunctivitis = measles suspect case. Maculopapular rash + arthralgia + post-auricular / sub-occipital lymphadenopathy in an unvaccinated person = rubella suspect case (PREGNANCY RISK — congenital rubella syndrome). Maculopapular rash that may involve palms/soles + eschar at a tick-bite site + fever = rickettsial spotted fever / scrub typhus. Maculopapular rash + retro-orbital pain + breakbone myalgia = dengue.',
    rash_distribution:
      'Measles rash is centrifugal (cephalocaudal): it begins on the face / hairline and spreads downward to trunk and limbs over 3 days. Rubella rash also begins on the face but fades within 3 days and is finer / less confluent. Rickettsial maculopapular rash classically begins on wrists/ankles and may extend to palms and soles. Dengue rash appears on day 3–5 ("islands of white in a sea of red") and is most prominent on the trunk and limbs.',
    who_source:
      'WHO IDSR Technical Guidelines 2021 §8 (Rash Illness with Fever); WHO Measles & Rubella Surveillance Standards 2018; WHO Disease Fact Sheets.',
  },

  rash_vesicular_pustular: {
    definition:
      'A fluid-filled (vesicular) or pus-filled (pustular) eruption with discrete lesions. In a febrile traveller it gates Mpox, smallpox (eradicated — bioterrorism sentinel), varicella, herpes simplex / zoster and severe drug eruptions.',
    clinical_signs: [
      'Discrete vesicles (clear fluid) progressing to pustules (cloudy / pus)',
      'Often painful or pruritic',
      'May involve palms and soles (Mpox, smallpox)',
      'Genital, perianal or oral mucosal lesions (Mpox clade Ib)',
      'Painful regional lymphadenopathy (Mpox — the major differentiator from smallpox)',
      'Eventual crusting and scarring',
    ],
    differentiation:
      'Lymphadenopathy is the major differentiator of Mpox from smallpox. Mpox lesions classically appear in the SAME stage simultaneously, frequently involve palms / soles and may include genital / perianal lesions in clade Ib. Smallpox (now eradicated, bioterrorism sentinel) presents as a centrifugal vesicular/pustular rash with all lesions at the same stage of development on face, arms and trunk after a 2–4 day febrile prodrome. Varicella lesions appear in CROPS at different stages on a centripetal (trunk-predominant) distribution and are rarely on palms or soles.',
    rash_distribution:
      'Mpox and smallpox are CENTRIFUGAL — lesions are denser on the face and extremities (including palms and soles) than on the trunk. Varicella is CENTRIPETAL — lesions are denser on the trunk than on the extremities and typically spare palms and soles. In Mpox clade Ib, genital, perianal and oral mucosal lesions are common and may be the first or only finding.',
    progression:
      'Mpox lesions progress through six WHO-defined stages over roughly 2–4 weeks: macule (flat red spot, day 1–2) → papule (raised firm bump, day 2–3) → vesicle (clear-fluid blister, day 4–5) → pustule (pus-filled, day 5–7, often umbilicated) → crust / scab (day 7–14) → scar (post-scab depigmentation or pitting). All lesions on a single body region tend to be at the same stage at the same time — this is the Mpox / smallpox hallmark.',
    who_source:
      'WHO Mpox (Monkeypox) Clinical Management and Infection Prevention 2024; WHO IDSR Technical Guidelines 2021 §8; WHO Smallpox / Mpox Surveillance Case Definitions; WHO Disease Outbreak News.',
  },

  petechial_or_purpuric_rash: {
    definition:
      'Pinpoint (petechiae, < 2 mm) or larger (purpura, ≥ 2 mm) non-blanching red-to-purple skin lesions caused by extravasation of blood. They do not blanch under pressure (glass / tumbler test). In a febrile patient they signal meningococcaemia, severe dengue, VHF or rickettsial disease until proven otherwise.',
    clinical_signs: [
      'Non-blanching red / purple spots — they do NOT fade under pressure',
      'Petechiae are pinpoint (< 2 mm); purpura are larger (≥ 2 mm)',
      'May rapidly coalesce into widespread ecchymosis',
      'Often accompanied by fever, headache and stiff neck',
      'May be associated with rapid clinical deterioration',
      'Bleeding from gums, nose, GI or injection sites in advanced disease',
    ],
    differentiation:
      'Sudden-onset fever + stiff neck OR petechial / purpuric rash, OR any case of acute meningitis or meningoencephalitis, meets the WHO meningococcal disease suspect case definition — emergency antibiotics (ceftriaxone) without delay. Petechial / purpuric rash with fever and shock and rapid clinical deterioration suggests meningococcaemia or severe dengue with plasma-leakage. Petechiae with thrombocytopenia after dengue fever defervescence = dengue haemorrhagic fever / shock syndrome. Petechial rash with tick-bite or livestock exposure raises CCHF and rickettsial disease.',
    rash_distribution:
      'Meningococcal petechial / purpuric rash typically starts on the trunk and lower limbs and may rapidly become widespread and ecchymotic. Dengue petechiae are most often on the lower extremities and may be elicited by a positive tourniquet test. Rickettsial petechiae often follow an initial maculopapular phase and may involve palms and soles in spotted-fever rickettsioses.',
    who_source:
      'WHO IDSR Technical Guidelines 2021 §8 (Meningitis / Acute Haemorrhagic Fever); WHO Meningitis Outbreak Response in the African Meningitis Belt 2014; WHO Dengue Guidelines 2009.',
  },

  watery_diarrhea: {
    definition:
      'Three or more loose or liquid stools in 24 hours. Profuse painless watery diarrhoea — particularly with rice-water appearance — in any person aged ≥ 5 years gates the WHO cholera suspect case definition.',
    clinical_signs: [
      'Three or more loose / liquid stools in 24 hours',
      'Profuse, painless, large-volume losses in cholera',
      'Rice-water stools (cloudy white with flecks of mucus) in cholera',
      'Rapid onset of severe dehydration: sunken eyes, slow skin pinch, weak pulse, hypotension',
      'Muscle cramps from electrolyte loss',
      'Usually no or only low-grade fever in cholera',
    ],
    differentiation:
      'Acute profuse watery (rice-water) diarrhoea with or without vomiting AND signs of severe dehydration or shock, in an area with known cholera activity — OR any person with profuse rice-water stool regardless of age in an epidemic setting — meets the WHO cholera suspect case definition. Aggressive IV / ORS rehydration and enteric precautions are immediate priorities. Watery diarrhoea WITHOUT rice-water stools and WITHOUT severe dehydration is classified as non-cholera acute diarrhoea.',
    who_source:
      'WHO Global Task Force on Cholera Control: Cholera Surveillance and Outbreak Response 2017; WHO IDSR Technical Guidelines 2021 §8 (Acute Watery Diarrhoea); WHO IHR (2005) Annex 2.',
  },

  bloody_diarrhea: {
    definition:
      'Acute diarrhoea with visible blood in the stool (dysentery), often with fever and tenesmus. It defines the WHO bloody-diarrhoea / dysentery syndrome and gates Shigellosis, EHEC, amoebiasis and the GI phase of Ebola virus disease.',
    clinical_signs: [
      'Loose stools with visible blood and / or mucus',
      'Tenesmus (painful urge with little stool passage)',
      'Lower abdominal cramping',
      'Fever, often high',
      'Risk of dehydration and electrolyte loss',
      'In children: prostration, poor feeding, lethargy',
    ],
    differentiation:
      'Acute diarrhoea with blood in stool AND fever AND tenesmus meets the WHO shigellosis / dysentery suspect case definition. In a febrile traveller, bloody diarrhoea with intense fatigue, vomiting and any bleeding raises Ebola virus disease — apply the VHF protocol. Bloody diarrhoea is NOT typical of cholera and its presence argues against the cholera diagnosis. EHEC dysentery may progress to haemolytic-uraemic syndrome (especially in children).',
    who_source:
      'WHO AFRO IDSR Technical Guidelines 2021 §8 (Bloody Diarrhoea / Dysentery case definition); WHO Diarrhoeal Disease Fact Sheet; WHO IHR (2005) Annex 2.',
  },

  severe_dehydration: {
    definition:
      'Loss of body fluids sufficient to cause haemodynamic compromise. In an adult or child with acute watery diarrhoea, severe dehydration plus rice-water stools satisfies the cholera suspect case definition and demands immediate IV rehydration.',
    clinical_signs: [
      'Lethargy, decreased level of consciousness or irritability',
      'Sunken eyes; sunken fontanelle in infants',
      'Skin pinch goes back very slowly (≥ 2 seconds)',
      'Unable to drink or drinking poorly',
      'Weak / absent peripheral pulse, hypotension, cold extremities',
      'Reduced or absent urine output',
    ],
    differentiation:
      'Severe dehydration plus profuse painless rice-water diarrhoea (with or without vomiting) in any person aged ≥ 5 years in a cholera-active area meets the WHO cholera suspect case definition. Severe dehydration in a young child with non-bloody watery diarrhoea points to rotavirus / norovirus / ETEC. Severe dehydration after bloody diarrhoea raises shigellosis or EHEC. Aggressive IV or ORS rehydration is the emergency priority regardless of cause.',
    who_source:
      'WHO / UNICEF IMCI Pocket Book of Hospital Care for Children 2013; WHO Global Task Force on Cholera Control 2017; WHO IDSR Technical Guidelines 2021 §8.',
  },

  stiff_neck: {
    definition:
      'Painful neck stiffness with resistance to passive flexion of the neck (nuchal rigidity). In a febrile patient it is the cardinal sign of meningitis / meningoencephalitis and gates the WHO meningococcal disease suspect case definition.',
    clinical_signs: [
      'Pain and resistance on passive flexion of the neck',
      'Positive Kernig and Brudzinski signs',
      'Severe headache and photophobia',
      'High fever',
      'Vomiting',
      'Altered consciousness, seizures or focal neurological signs in advanced disease',
    ],
    differentiation:
      'Sudden-onset fever AND stiff neck — OR petechial / purpuric rash, OR any case of acute meningitis or meningoencephalitis — meets the WHO meningococcal meningitis suspect case definition. Emergency antibiotics (ceftriaxone) must be given without delay; droplet precautions apply. The classical meningismus triad is fever + stiff neck + photophobia. In the African Meningitis Belt and during Hajj, clusters strongly suggest meningococcal disease.',
    who_source:
      'WHO Standard Operating Procedures for Surveillance of Meningitis in the African Meningitis Belt 2019; WHO IDSR Technical Guidelines 2021 §8 (Meningitis); WHO IHR (2005) Annex 2.',
  },

  paralysis_acute_flaccid: {
    definition:
      'Acute onset (over 1–3 days) of flaccid (floppy) paralysis in any limb, without sensory loss. In any child < 15 years — or any older person with suspected polio — this triggers the WHO Acute Flaccid Paralysis (AFP) surveillance case definition.',
    clinical_signs: [
      'Sudden-onset weakness in one or more limbs (typically asymmetric)',
      'Floppy (flaccid) tone, NOT spastic',
      'Reduced or absent deep tendon reflexes',
      'Sensation preserved (no sensory loss)',
      'Rapid progression over 1–3 days',
      'Often preceded by a brief febrile prodrome',
    ],
    differentiation:
      'Any child < 15 years with acute flaccid paralysis (AFP) — OR any person with AFP suspected to be polio regardless of age — meets the WHO AFP suspect case definition. Two stool specimens 24–48 h apart within 14 days of paralysis onset must be collected. The classical features that distinguish polio AFP are asymmetric flaccid paralysis, absence of sensory loss, rapid onset (1–3 days) and a preceding fever / prodrome. Guillain–Barré syndrome is symmetric and ascending; transverse myelitis has a sensory level.',
    who_source:
      'WHO Polio Surveillance Standards 2019 (Global Polio Eradication Initiative); WHO IDSR Technical Guidelines 2021 §8 (Acute Flaccid Paralysis); WHO IHR (2005) Annex 2.',
  },

  hydrophobia: {
    definition:
      'Painful pharyngeal / laryngeal spasm at the sight, sound or attempt to swallow water. Hydrophobia is virtually pathognomonic for furious-form rabies in a person with a relevant animal-exposure history.',
    clinical_signs: [
      'Painful spasm of throat muscles when attempting to drink',
      'Spasm may be triggered by the sight or sound of water',
      'Associated severe anxiety, agitation and hyperexcitability',
      'Hypersalivation ("foaming")',
      'Difficulty swallowing even saliva',
      'Fluctuating consciousness between lucid intervals and agitation',
    ],
    differentiation:
      'HYDROPHOBIA + AEROPHOBIA in a person with an animal bite or wildlife contact history is pathognomonic for rabies (virtually 100 % specific). Once symptomatic, rabies is virtually 100 % fatal — the POE role is to identify, document, contact-trace and refer; treatment is palliative. The long incubation (4–90 days, typically 20–90) means rabies can present at any POE.',
    who_source:
      'WHO Rabies Vaccines: WHO Position Paper 2018; WHO Expert Consultation on Rabies (Third Report) 2018; WHO Rabies Fact Sheet 2024 (Zero by 30 strategy).',
  },

  swollen_lymph_nodes: {
    definition:
      'Enlargement of one or more lymph nodes (lymphadenopathy), localised or generalised. In a febrile traveller, lymphadenopathy is a key differentiator for Mpox versus smallpox and a cardinal feature of plague, rubella, tularaemia and Lassa fever.',
    clinical_signs: [
      'Visible or palpable enlargement of one or more lymph nodes',
      'Cervical, axillary, inguinal or generalised distribution',
      'May be tender or non-tender',
      'May be matted or discrete',
      'Often accompanies fever, malaise and a primary skin or mucosal lesion',
      'Post-auricular / sub-occipital location is classical for rubella',
    ],
    differentiation:
      'Lymphadenopathy is the MAJOR differentiator of Mpox from smallpox — it is present in Mpox and absent in smallpox. Post-auricular / sub-occipital lymphadenopathy with a maculopapular rash and arthralgia in an unvaccinated person points to rubella. Generalised lymphadenopathy with fever and a primary skin lesion may indicate tularaemia or anthrax. Cervical / axillary / inguinal lymphadenopathy with skin or mucosal lesions and fever raises Mpox.',
    who_source:
      'WHO Mpox (Monkeypox) Clinical Management 2024; WHO IDSR Technical Guidelines 2021 §8; WHO Measles & Rubella Surveillance Standards 2018.',
  },

  painful_swollen_lymph_nodes: {
    definition:
      'An exquisitely tender, rapidly enlarging lymph node ("bubo") in the inguinal, axillary or cervical region. In a febrile patient with relevant exposure it gates the WHO bubonic plague suspect case definition.',
    clinical_signs: [
      'Rapid onset (2–6 days) of a single, exquisitely painful, swollen node',
      'Inguinal, axillary or cervical location',
      'Overlying skin may be warm and erythematous',
      'High fever, chills, severe prostration',
      'Tachycardia, hypotension in advanced disease',
      'May progress to septicaemic or pneumonic plague',
    ],
    differentiation:
      'Fever + painful swollen lymph nodes (buboes) — especially in a plague-endemic area or with flea / rodent exposure within 7 days — meets the WHO bubonic plague suspect case definition. Urgent antibiotics, contact precautions and surveillance notification are required. Tularaemia (rabbit fever) can present with fever, an eschar and painful lymphadenopathy (ulceroglandular form). Suppurative bacterial lymphadenitis (Staph / Strep) is usually more localised and lacks systemic toxicity.',
    who_source:
      'WHO Plague Manual: Epidemiology, Distribution, Surveillance and Control 1999; WHO IDSR Technical Guidelines 2021 §8 (Plague); WHO IHR (2005) Annex 2.',
  },

  severe_headache: {
    definition:
      'Headache of unusual severity, sudden onset or progressive intensity in a febrile traveller. It is a hallmark of meningitis, Marburg / Lassa / Ebola virus disease, dengue, malaria and West Nile virus.',
    clinical_signs: [
      'Pain rated severe by the patient or unusual for them',
      'Sudden ("thunderclap") or rapidly progressive onset',
      'Associated photophobia, phonophobia or vomiting',
      'May radiate to the neck (suggests meningismus)',
      'Retro-orbital pain (pain behind the eyes) is classical for dengue',
      'Frontal severe headache with abrupt onset is classical for Marburg',
    ],
    differentiation:
      'Abrupt, intense headache plus high fever plus severe fatigue raises Marburg virus disease — apply the VHF protocol. Severe headache with stiff neck and photophobia raises bacterial meningitis (notably meningococcal) — emergency antibiotics. Severe headache with retro-orbital pain (pain behind the eyes) is classical for dengue. Severe headache plus fever plus altered consciousness plus impaired mental status raises cerebral malaria, encephalitis (Nipah, JE, West Nile) or rabies.',
    who_source:
      'WHO IDSR Technical Guidelines 2021 §8 (Meningitis / Acute Haemorrhagic Fever); WHO Marburg, Lassa and Dengue Fact Sheets; WHO IHR (2005) Annex 2.',
  },

  altered_consciousness: {
    definition:
      'Reduction in level of consciousness, confusion, disorientation, agitation or coma. It signals central-nervous-system involvement and is a danger sign for cerebral malaria, meningoencephalitis (bacterial, viral, Nipah, JE), rabies, severe sepsis and end-stage VHF.',
    clinical_signs: [
      'Confusion, disorientation or inability to follow commands',
      'Drowsiness progressing to lethargy',
      'Stupor or coma in advanced disease',
      'May be associated with seizures',
      'Focal neurological signs in encephalitis or stroke',
      'Glasgow Coma Scale < 15 in any adult febrile patient is a danger sign',
    ],
    differentiation:
      'Cerebral malaria: impaired consciousness PLUS fever in a person from or returning from a malaria-endemic area is a medical emergency requiring IV artesunate. Encephalitis (fever + altered consciousness ± seizures) plus contact with pigs, bats or fruit / date-palm sap in South / Southeast Asia within 21 days = Nipah virus suspect. Encephalitis plus an animal bite within 1 year in a rabies-endemic country — especially with hydrophobia — = rabies suspect. Altered consciousness plus stiff neck plus fever = meningoencephalitis.',
    who_source:
      'WHO Severe Malaria Guidelines 2015 (3rd Edition); WHO IDSR Technical Guidelines 2021 §8 (Meningitis / Encephalitis Syndrome); WHO Nipah and Rabies Fact Sheets.',
  },

  skin_eschar: {
    definition:
      'A painless, black, necrotic skin ulcer with a depressed centre, often with surrounding oedema. The painlessness is the hallmark and is the most useful differentiator from all other skin ulcers.',
    clinical_signs: [
      'Painless black necrotic central ulcer',
      'Depressed (excavated) centre',
      'Surrounding ring of oedema and erythema',
      'Often no pus and no severe local pain',
      'Regional lymphadenopathy',
      'May be accompanied by fever and malaise',
    ],
    differentiation:
      'Painless skin ulcer with a central black eschar — especially after contact with animal hides, wool, meat or soil in endemic areas — meets the WHO cutaneous anthrax suspect case definition. The painlessness differentiates anthrax from virtually every other ulcer. Multiple simultaneous cases of cutaneous anthrax are a bioterrorism sentinel and must be escalated immediately. An eschar at the site of a tick / chigger bite, with fever, headache and a maculopapular rash, points to scrub typhus or spotted-fever rickettsiosis. An eschar with painful regional lymphadenopathy and fever raises tularaemia (ulceroglandular form). DO NOT incise — risk of systemic spread.',
    who_source:
      'WHO Anthrax in Humans and Animals (4th Edition) 2008; WHO IDSR Technical Guidelines 2021 §8; WHO Anthrax Fact Sheet; WHO Disease Outbreak News.',
  },

  hearing_loss: {
    definition:
      'Acute or subacute partial or complete loss of hearing, unilateral or bilateral. In the context of an acute febrile illness with travel from West Africa, hearing loss is a recognised hallmark and late sequela of Lassa fever.',
    clinical_signs: [
      'Reduced or absent ability to hear conversation',
      'Tinnitus or sense of "blocked" ears',
      'May be unilateral or bilateral',
      'Often subacute, developing during the second week of illness',
      'May be permanent and is a recognised Lassa sequela in up to one third of survivors',
      'Associated with fever, sore throat, malaise and headache in Lassa',
    ],
    differentiation:
      'Acute hearing loss in a febrile traveller from a Lassa-endemic area (West Africa) — particularly with gradual-onset fever, sore throat, chest pain or facial oedema — strongly suggests Lassa fever. Hearing loss is uncommon in other VHFs and is one of the few clinical clues that distinguishes Lassa from Ebola / Marburg in early disease. Other causes (drug ototoxicity, sudden sensorineural hearing loss, otitis media) lack the febrile / endemic-travel context.',
    who_source:
      'WHO Lassa Fever Fact Sheet; WHO IDSR Technical Guidelines 2021 §8 (Acute Haemorrhagic Fever Syndrome); WHO Disease Outbreak News.',
  },

  mucosal_lesions: {
    definition:
      'Lesions on the oral, genital, anal or conjunctival mucosa — vesicles, pustules, ulcers or erosions. In a febrile traveller they gate Mpox (clade Ib in particular), measles (Koplik spots), severe drug eruptions and herpes simplex.',
    clinical_signs: [
      'Vesicles, pustules, ulcers or erosions on mucosal surfaces',
      'Oral, pharyngeal, genital, perianal or conjunctival sites',
      'May be painful (pain on swallowing, urination or defaecation)',
      'May precede or accompany a generalised cutaneous rash',
      'Associated regional (cervical, inguinal) lymphadenopathy',
      'Koplik spots (small bluish-white spots on a red base on buccal mucosa) are pathognomonic for measles',
    ],
    differentiation:
      'Mucosal lesions plus a vesicular / pustular skin rash plus fever plus painful regional lymphadenopathy raises Mpox — clade Ib in particular often presents with prominent genital or perianal lesions. Koplik spots — small bluish-white spots on a red base on the buccal mucosa — are pathognomonic for measles and appear before the cutaneous rash. Painful oral and genital ulcers without skin pustules suggest herpes simplex. Widespread mucosal erosions with skin sloughing raise Stevens–Johnson syndrome / toxic epidermal necrolysis.',
    who_source:
      'WHO Mpox Clinical Management and Infection Prevention 2024; WHO Measles & Rubella Surveillance Standards 2018; WHO IDSR Technical Guidelines 2021 §8.',
  },

  shortness_of_breath: {
    definition:
      'A subjective sense of breathlessness or air hunger, often with objectively increased respiratory rate or chest indrawing. It is a danger sign in any febrile respiratory illness and gates SARI, SARS, MERS-CoV, novel influenza, COVID-19 and pulmonary anthrax.',
    clinical_signs: [
      'Subjective sense of breathlessness',
      'Increased respiratory rate (≥ 30 / min adult, age-specific in children)',
      'Use of accessory respiratory muscles',
      'Chest indrawing (children) or intercostal retractions',
      'Cyanosis, low SpO₂',
      'Inability to complete a sentence in one breath',
    ],
    differentiation:
      'Fever + cough + shortness of breath = Severe Acute Respiratory Infection (SARI). Add travel from / to or residence in the Arabian Peninsula within 14 days, or close contact with a confirmed MERS case = MERS-CoV suspect. Add live-poultry / pigs / animal exposure or contact with a confirmed H5N1 / H7N9 / novel influenza case within 10 days = zoonotic / novel influenza suspect. Rapidly progressive respiratory distress with widened mediastinum on chest X-ray and a brief mild prodrome = pulmonary / inhalation anthrax — activate the bioterrorism protocol.',
    who_source:
      'WHO Global Influenza Surveillance Standards 2014 (SARI / ILI); WHO MERS-CoV Surveillance and Case Definitions 2018; WHO IDSR Technical Guidelines 2021 §8 (Severe Acute Respiratory Infection).',
  },

  difficulty_breathing: {
    definition:
      'Objectively or subjectively impaired breathing — increased work of breathing, abnormal respiratory rate, or sensation of inability to breathe. In children it is an IMCI danger sign; in adults it gates SARI / pneumonia / ARDS surveillance.',
    clinical_signs: [
      'Increased work of breathing (nasal flaring, accessory muscle use)',
      'Tachypnoea (age-specific cut-offs)',
      'Chest indrawing or intercostal retractions in children',
      'Stridor or wheeze',
      'Inability to feed (children) or speak in full sentences (adults)',
      'Cyanosis or low oxygen saturation',
    ],
    differentiation:
      'Fever + cough + difficulty breathing = SARI. Same triad with travel to or contact with a SARS / MERS / novel-influenza setting raises the corresponding IHR-notifiable suspect. In a child < 5 years, fever + cough + chest indrawing = severe pneumonia per WHO IMCI and requires urgent referral. Difficulty breathing with stridor and drooling raises epiglottitis or diphtheria. Difficulty breathing with cyanosis plus widened mediastinum on chest X-ray = inhalation anthrax until proven otherwise.',
    who_source:
      'WHO IMCI Pocket Book of Hospital Care for Children 2013; WHO Global Influenza Surveillance Standards 2014; WHO IDSR Technical Guidelines 2021 §8 (SARI).',
  },

  // ── Additional WHO/IDSR-sourced entries (added 2026-05-05) ─────────
  high_fever: {
    definition: 'High fever is documented core temperature ≥ 39.0 °C (102.2 °F). High fever in a returning traveller is an IHR Annex 2 trigger and warrants malaria, VHF, and severe sepsis screening.',
    clinical_signs: ['Tympanic / oral / axillary T ≥ 39.0 °C', 'Tachycardia disproportionate to fever', 'Rigors and chills', 'Mental status change in elderly', 'Often unresponsive to a single dose of antipyretic'],
    differentiation: 'Persistent high fever in a traveller from a malaria-endemic area is severe malaria until proven otherwise. High fever with bleeding is suspect VHF (gate at Step 2). High fever with stiff neck = meningitis suspect.',
    who_source: 'WHO Severe Malaria Guidelines 2024; WHO IDSR Technical Guidelines 2021 §6.',
  },
  low_grade_fever: {
    definition: 'Low-grade fever is core temperature 37.5–38.4 °C. Common in viral prodromes, early TB, and chronic infections; also seen in Mpox prodrome and measles incubation.',
    clinical_signs: ['T 37.5–38.4 °C sustained > 24 h', 'Mild malaise without rigors', 'Often paired with low-grade systemic symptoms (fatigue, headache)'],
    differentiation: 'Low-grade fever + lymphadenopathy + rash → measles, mpox, rubella prodrome. Low-grade fever + cough > 2 weeks → pulmonary TB workup per WHO TB End-TB strategy.',
    who_source: 'WHO IDSR Technical Guidelines 2021; WHO End TB Strategy 2015.',
  },
  sudden_onset_fever: {
    definition: 'Abrupt fever onset (< 24 h from well to febrile, often with rigors). Sudden onset is a hallmark of severe malaria, viral haemorrhagic fevers, plague, and rickettsial disease.',
    clinical_signs: ['Witnessed transition from afebrile to ≥ 38.5 °C in < 24 h', 'Severe rigors / chills', 'Headache, myalgia, prostration', 'Often nausea or vomiting'],
    differentiation: 'Sudden fever in a traveller is a malaria, VHF, or rickettsial alert until ruled out. Sudden fever with eschar + travel to bush / tick area = scrub typhus / African tick-bite fever.',
    who_source: 'WHO Severe Malaria Guidelines 2024; WHO IDSR §6 (VHF).',
  },
  chills: {
    definition: 'Sensation of coldness with shivering / shaking, usually preceding a fever spike. Severe rigors are a malaria and bacteraemia signal.',
    clinical_signs: ['Visible shivering, teeth chattering', 'Followed by sweating phase', 'Often paired with rigors / muscle stiffening'],
    differentiation: 'Periodic rigors every 48–72 h in a traveller = malaria pattern (P. vivax / falciparum). Rigors at fever onset + flank pain = pyelonephritis / urosepsis.',
    who_source: 'WHO Severe Malaria Guidelines 2024.',
  },
  headache: {
    definition: 'Persistent or recurrent pain in any region of the head. Headache is an IDSR-listed early sign of meningitis, malaria, dengue, and viral encephalitis.',
    clinical_signs: ['Unilateral or bilateral cephalalgia', 'May be throbbing, pressure, or band-like', 'Photophobia or phonophobia', 'Nausea / vomiting if severe'],
    differentiation: 'Headache with neck stiffness or altered mental status = meningitis suspect (urgent LP). Severe headache + retro-orbital pain + rash = dengue. Headache with thunderclap onset = SAH / cerebral haemorrhage.',
    who_source: 'WHO IDSR Technical Guidelines 2021 §7 (Meningitis).',
  },
  muscle_pain: {
    definition: 'Diffuse skeletal muscle aching (myalgia). Common to viral prodromes; severe myalgia is a hallmark of dengue, leptospirosis, influenza, and chikungunya.',
    clinical_signs: ['Bilateral large-muscle ache (back, thighs, calves)', 'Tenderness on palpation', 'Often paired with fever and fatigue'],
    differentiation: 'Severe myalgia + retro-orbital pain + rash = dengue. Myalgia + jaundice + conjunctival suffusion in flooded area = leptospirosis. Myalgia + sudden joint pain in Indian Ocean / Africa traveller = chikungunya.',
    who_source: 'WHO Dengue Fact Sheet 2024; WHO Leptospirosis Surveillance Tools 2003.',
  },
  joint_pain: {
    definition: 'Pain in joints (arthralgia) with or without swelling. Acute polyarthralgia in a returning traveller is a chikungunya, dengue, and Zika pointer.',
    clinical_signs: ['Symmetric small-joint pain (wrists, ankles, hands)', 'May be debilitating in chikungunya', 'Sometimes with effusion', 'Persists weeks–months after fever clears'],
    differentiation: 'Severe disabling polyarthralgia after Aedes-mosquito exposure = chikungunya until proven otherwise. Joint pain with rash + conjunctivitis in pregnancy zone = Zika.',
    who_source: 'WHO Chikungunya Fact Sheet 2024; WHO Zika Strategic Response 2016.',
  },
  fatigue: {
    definition: 'Subjective sense of tiredness or lack of energy disproportionate to recent activity. Can mask serious infection in elderly or immunocompromised.',
    clinical_signs: ['Generalised weakness', 'Reduced exercise tolerance', 'Often paired with fever, anorexia, weight loss'],
    differentiation: 'Fatigue with persistent low-grade fever + night sweats + cough > 2 weeks → TB suspect. Fatigue + jaundice + dark urine → viral hepatitis.',
    who_source: 'WHO Tuberculosis surveillance 2023; WHO IDSR §8.',
  },
  severe_fatigue: {
    definition: 'Profound exhaustion preventing routine activities (e.g. inability to walk independently or feed self). A WHO IDSR severity marker for several syndromes.',
    clinical_signs: ['Bed-bound or near-bed-bound', 'Often paired with prostration and tachycardia', 'May herald sepsis or severe malaria'],
    differentiation: 'Severe fatigue + fever in malaria-endemic traveller → severe malaria triage. Severe fatigue + jaundice + bleeding → late-stage VHF.',
    who_source: 'WHO Severe Malaria Guidelines 2024.',
  },
  weakness: {
    definition: 'Reduced muscle power. Can be focal (suggesting neurological lesion) or generalised (suggesting systemic illness, electrolyte disturbance, or dehydration).',
    clinical_signs: ['Inability to lift limbs against gravity (focal)', 'Generalised lassitude', 'Tone often normal early', 'May progress to paralysis'],
    differentiation: 'Acute focal weakness with no trauma = stroke / Guillain-Barré / poliomyelitis (AFP per IDSR). Generalised weakness with severe diarrhoea = electrolyte loss / cholera.',
    who_source: 'WHO IDSR Technical Guidelines 2021 §6 (AFP).',
  },
  anorexia: {
    definition: 'Loss of appetite. Non-specific but a near-universal early sign in viral hepatitides, typhoid, malaria, and many febrile illnesses.',
    clinical_signs: ['Refusal of usual foods', 'Early satiety', 'May progress to weight loss if prolonged'],
    differentiation: 'Anorexia + jaundice + dark urine → viral hepatitis. Anorexia + persistent cough + night sweats → TB.',
    who_source: 'WHO Viral Hepatitis Strategy 2022–2030.',
  },
  nausea: {
    definition: 'Subjective sensation of impending vomiting. Common in gastrointestinal infections, raised intracranial pressure, and pregnancy.',
    clinical_signs: ['Salivation, retching', 'Often precedes vomiting', 'May be paired with abdominal cramping'],
    differentiation: 'Nausea + headache + photophobia → meningitis / migraine. Nausea + abdominal pain + watery stool → enteric infection.',
    who_source: 'WHO IMAI Acute Care Guidelines.',
  },
  vomiting: {
    definition: 'Forceful expulsion of gastric contents. Severe or persistent vomiting causes dehydration and electrolyte loss; in cholera contexts pairs with watery diarrhoea.',
    clinical_signs: ['Episodic emesis', 'May be projectile (in raised ICP)', 'Bilious or non-bilious', 'Bloody = haematemesis (URGENT)'],
    differentiation: 'Vomiting + watery diarrhoea + dehydration in cholera-endemic area = suspect cholera. Bloody vomit (haematemesis) is a VHF / GI bleed alert.',
    who_source: 'WHO Cholera Outbreak Response Field Manual 2019.',
  },
  diarrhea: {
    definition: 'Three or more loose / watery stools in 24 hours. WHO IDSR notifiable when bloody, profuse, or in outbreak settings.',
    clinical_signs: ['Loose / unformed stools ≥ 3 / 24 h', 'May be paired with cramping', 'Watch for dehydration markers (sunken eyes, skin turgor)'],
    differentiation: 'Profuse rice-water stool + vomiting = cholera suspect. Bloody diarrhoea = dysentery (Shigella, EHEC, amoebic).',
    who_source: 'WHO IDSR Technical Guidelines 2021 §7; WHO Cholera Outbreak Response Field Manual 2019.',
  },
  rice_water_diarrhea: {
    definition: 'Painless, profuse, watery stool with flecks of mucus resembling water in which rice has been washed. Pathognomonic for cholera (Vibrio cholerae O1/O139).',
    clinical_signs: ['Stool volume often > 1 litre / hour', 'No blood, no faecal odour', 'Rapid onset of severe dehydration', 'Vomiting common'],
    differentiation: 'Rice-water stool in any context is cholera until proven otherwise. WHO declares cholera notifiable; trigger ORS / IV resuscitation immediately.',
    who_source: 'WHO Cholera Outbreak Response Field Manual 2019; WHO IHR Annex 2.',
  },
  abdominal_pain: {
    definition: 'Pain in any abdominal quadrant. Differentiation by location and severity guides workup; in travellers, generalised pain with fever raises typhoid, hepatitis, and surgical abdomen.',
    clinical_signs: ['Localised vs diffuse', 'Sharp / cramping / dull', 'Watch for rebound tenderness, rigidity', 'Pair with fever, vomiting, jaundice'],
    differentiation: 'RUQ pain + jaundice → hepatitis / cholangitis. Diffuse pain + fever + altered consciousness → typhoid suspect. Acute severe pain + rigidity → surgical abdomen.',
    who_source: 'WHO IMAI Acute Care Guidelines.',
  },
  dark_urine: {
    definition: 'Tea or cola-coloured urine. Indicates conjugated bilirubinuria (cholestatic / hepatocellular jaundice) or haemoglobinuria (severe haemolysis, blackwater fever).',
    clinical_signs: ['Visibly dark amber, tea, or cola colour', 'Often paired with pale stool (cholestasis)', 'May follow severe rigors (haemolysis)'],
    differentiation: 'Dark urine + jaundice → viral hepatitis. Dark urine + severe malaria + anaemia = blackwater fever (haemoglobinuria).',
    who_source: 'WHO Severe Malaria Guidelines 2024; WHO Hepatitis Strategy 2022–2030.',
  },
  sore_throat: {
    definition: 'Pharyngeal pain or scratchiness. WHO IDSR pharyngitis surveillance is a sentinel for diphtheria where pseudomembrane is present.',
    clinical_signs: ['Erythematous oropharynx', 'Pain on swallowing', 'May have exudate / tonsillar enlargement', 'Pseudomembrane is a diphtheria flag'],
    differentiation: 'Pharyngitis + grey adherent pseudomembrane + bull-neck = diphtheria suspect. Pharyngitis + rash + strawberry tongue = scarlet fever.',
    who_source: 'WHO Diphtheria Vaccine Position Paper 2017; WHO IDSR §6.',
  },
  coryza: {
    definition: 'Acute rhinitis: clear or mucopurulent nasal discharge, sneezing, and nasal congestion. A core part of the measles prodrome triad (cough, coryza, conjunctivitis).',
    clinical_signs: ['Watery rhinorrhoea, then mucopurulent', 'Sneezing', 'Nasal stuffiness', 'Often paired with cough and conjunctivitis'],
    differentiation: 'Coryza + cough + conjunctivitis + fever 3–5 days before rash = measles prodrome. Isolated coryza is usually viral URI.',
    who_source: 'WHO Measles & Rubella Surveillance Standards 2018.',
  },
  conjunctivitis: {
    definition: 'Inflammation of the conjunctiva: redness, watering, and discharge. In travel medicine, conjunctival injection / suffusion is a leptospirosis and measles signal.',
    clinical_signs: ['Bilateral red eyes', 'Watery or purulent discharge', 'Photophobia possible', 'No corneal involvement (else keratitis)'],
    differentiation: 'Conjunctivitis + fever + rash = measles. Conjunctival suffusion (red eyes, no pus) + jaundice in flooded area = leptospirosis. Conjunctivitis + Aedes exposure + rash + arthralgia = Zika / chikungunya.',
    who_source: 'WHO Measles & Rubella Surveillance Standards 2018; WHO Leptospirosis Surveillance Tools 2003.',
  },
  loss_of_taste_smell: {
    definition: 'Acute or sub-acute loss / distortion of taste (ageusia / dysgeusia) or smell (anosmia / parosmia). Highly suggestive of SARS-CoV-2 in 2020–2023; may persist in long-COVID.',
    clinical_signs: ['Sudden inability to detect odour or taste', 'Often without nasal congestion', 'May be paired with fever, cough, fatigue'],
    differentiation: 'Acute anosmia + fever + cough = SARS-CoV-2 suspect (PCR). Persistent anosmia after viral infection = post-viral olfactory dysfunction.',
    who_source: 'WHO Clinical Management of COVID-19 Living Guidance 2024.',
  },
  rash_face_first: {
    definition: 'A rash that begins on the face / behind the ears and spreads downwards (cephalo-caudal progression). The classic descending pattern of measles and rubella.',
    clinical_signs: ['Onset behind ears, hairline, forehead', 'Spreads to trunk and limbs over 2–3 days', 'May coalesce into confluent erythema', 'Koplik spots in measles (oral mucosa, day 2–3)'],
    differentiation: 'Face-first descending maculopapular rash in measles = surveillance trigger; collect blood for IgM. Same pattern in rubella = arthralgia in adults, congenital risk in pregnancy.',
    who_source: 'WHO Measles & Rubella Surveillance Standards 2018.',
  },
  painful_rash: {
    definition: 'Cutaneous eruption with significant pain rather than itch. Mpox lesions are characteristically painful; herpes zoster is dermatomal and painful.',
    clinical_signs: ['Tenderness on palpation', 'Patient often guards lesion', 'Lesions may be umbilicated (mpox) or vesicular dermatomal (zoster)'],
    differentiation: 'Painful vesicular rash, deep-seated, in same stage of evolution + lymphadenopathy = mpox suspect. Painful vesicles in dermatomal stripe = herpes zoster.',
    who_source: 'WHO Mpox Clinical Guidance 2024.',
  },
  retroauricular_lymph_nodes: {
    definition: 'Tender, palpable lymph nodes behind the ears (post-auricular). Classic for rubella; also seen in scalp infections and some viral exanthems.',
    clinical_signs: ['Mobile, tender nodes 0.5–1.5 cm', 'Bilateral common', 'Associated suboccipital and posterior cervical adenopathy in rubella'],
    differentiation: 'Retroauricular + suboccipital + posterior cervical lymphadenopathy + maculopapular rash = rubella triad.',
    who_source: 'WHO Measles & Rubella Surveillance Standards 2018.',
  },
  bleeding_gums_or_nose: {
    definition: 'Mucosal bleeding from gingiva (gums) or nares (epistaxis). A WHO IDSR haemorrhagic-fever screening sign and a severe-dengue warning.',
    clinical_signs: ['Spontaneous gum bleed on brushing or at rest', 'Spontaneous nose bleed', 'May be paired with petechiae, easy bruising'],
    differentiation: 'Mucosal bleeding + travel to outbreak setting = VHF (Ebola, Marburg, Lassa, CCHF, RVF) — escalate. Mucosal bleeding + thrombocytopenia + dengue exposure = severe dengue (DHF/DSS).',
    who_source: 'WHO IDSR Technical Guidelines 2021 §6 (VHF); WHO Dengue Guidelines 2009.',
  },
  bloody_sputum: {
    definition: 'Haemoptysis: blood-streaked or frankly bloody sputum. WHO IDSR notifiable as a TB and pulmonary plague signal; also seen in pulmonary embolism and lung cancer.',
    clinical_signs: ['Visible blood in expectorated sputum', 'May be streaks or frank blood', 'Volume varies — massive (> 100 ml/24 h) is emergency'],
    differentiation: 'Haemoptysis + cough > 2 weeks + night sweats + weight loss = pulmonary TB. Haemoptysis + sudden onset + plague-endemic exposure = pneumonic plague (CRITICAL).',
    who_source: 'WHO TB Surveillance 2023; WHO Plague Manual 2008.',
  },
  seizures: {
    definition: 'Sudden uncontrolled neuronal discharge causing convulsions, altered awareness, or focal neurological signs. Febrile seizures common in children; in travellers, malaria, meningitis, and rabies must be ruled out.',
    clinical_signs: ['Tonic-clonic activity', 'Loss of consciousness possible', 'Post-ictal confusion', 'May be focal or generalised'],
    differentiation: 'Seizures + fever in malaria-endemic traveller = cerebral malaria. Seizures + neck stiffness = meningitis / encephalitis. Seizures + hydrophobia = rabies (terminal).',
    who_source: 'WHO Severe Malaria Guidelines 2024; WHO IDSR §7 (Meningitis); WHO Rabies PEP 2018.',
  },
}

export default SYMPTOM_INFO_BUNDLE
