# Uvođenje izmjena i održavanje Promore

## Priprema instalacije

1. Zaustaviti stari scheduler i worker i sačekati kraj aktivnih procesa. Sačuvati bazu i popis cron zadataka. Na kopiji iste baze provjeriti rokove promocija, aktivna vlasništva i tip vremenske kolone u tabeli webhook događaja. Migracija pretvara DATE u DATETIME. Za već izgubljene sate operator mora provjeriti originalni raspored.
2. Pokrenuti `php bin/migrate.php --status`, zatim `php bin/migrate.php --up`. Nove instalacije prolaze iste migracije kroz installer. Provjeriti dozvole za ALTER, indekse, generisane kolone i trigere. Ponovljeni `--up` nakon uspjeha treba da prijavi da nema novih migracija.
3. Ako migracija prijavi višestrukog vlasnika, pregledati grupe iz upita ispod. Uporediti istoriju primjene, cijenu i vrijeme s BigCommerce evidencijom. Sačuvati redove prije ručnog razrješenja i ponoviti migraciju. Druge prodavnice mogu imati iste ID-eve proizvoda.
4. Pregledati postojeće poslove u statusu `processing` bez tokena. Provjeriti da je stari izvršilac završen i uskladiti već izvršene udaljene promjene prije pojedinačnog vraćanja posla u red. Novi worker automatski preuzima istekle rezervacije koje imaju token.

```sql
SELECT store_hash, product_id, COALESCE(variant_id,0) AS variant_key, COUNT(*) AS owners
FROM promotion_products
GROUP BY store_hash, product_id, COALESCE(variant_id,0)
HAVING COUNT(*) > 1;

SELECT store_hash,id,job_type,processed_items,updated_at
FROM sync_jobs WHERE status='processing' AND lease_token IS NULL;
```

## Pokretanje i kontrola

- Raspored pokreće `php bin/scheduler.php` i `php bin/worker.php` kao odvojene procese. Za probnu prodavnicu postaviti `PROMORA_WORKER_STORES` na njen hash u okruženju svih aktivnih workera. Lista ograničava preuzimanje iz reda. Ručno pokrenute sinhronizacije i administrativne akcije imaju svoj postojeći opseg.
- Za HTTP trigere `bin/scheduler_trigger.php` i `bin/cron_trigger.php` podesiti HTTPS na serveru i zaglavlje `X-Cron-Token` čija vrijednost odgovara `CRON_AUTH_TOKEN`. Za direktni sinhroni prolaz jedne prodavnice ruta je `index.php?route=cron&store_hash=STORE` uz isto zaglavlje. Token čuvati u tajnama cron servisa. Hosting treba PHP CLI i podršku za pozadinske procese. Potvrda triggera znači da je pokretanje zatraženo. Ishod posla potražiti u aplikaciji.
- Worker rezerviše posao na pet minuta. Obnavlja rezervaciju pri paketima i tokom API prometa. Napredak sadrži kursor i stvarne brojače. Vremenski budžet jednog pokretanja je 55 sekundi, uz završetak tekućeg paketa. Djelimični ishod, greške pri upisu polja i terminalni webhookovi dostupni su u evidenciji. Neuspjeli webhook dobija zaseban pokušaj s odgodom. Treći neuspjeh označava kraj njegove obrade.
- Za zaustavljanje uvođenja zaustaviti nove trigere i sačekati aktivne procese. Grupna čitanja istorije mogu se isključiti preko `PROMORA_GROUP_HISTORY=0`. Pri vraćanju stare verzije prvo uskladiti preuzete poslove, neriješene namjere upisa i korekcije te provjeriti udaljene cijene. Vraćanje lokalne baze zahtijeva posebno usklađivanje cijena u prodavnici.

## Cijene i kataloški keš

- U **Logs → Price conflicts** operator vidi proizvod, varijantu, promociju i zatečenu cijenu za prodavnicu iz sesije. **Keep current price** izuzima stavku iz te promocije i zatvara njeno vlasništvo. **Approve sync** dozvoljava sljedeću primjenu ili vraćanje poznate prethodne vrijednosti. Ponovna izmjena zatečene cijene zahtijeva novu odluku. Za nepoznatu prethodnu cijenu pri vraćanju dostupno je zadržavanje postojeće vrijednosti. Tabela `price_conflict_decisions` čuva odluku, vrijednosti, operatera i reviziju.
- Cjenovni upis ima trajnu namjeru i provjeru udaljene vrijednosti. Ponovljena obrada poslije izgubljenog odgovora može završiti lokalne korake bez drugog upisa cijene. Potvrđeno vlasništvo s istom ciljnom i keširanom cijenom ima interval ponovne udaljene provjere od 15 minuta. BigCommerce uređivanje izvan aplikacije može se desiti između čitanja i upisa.
- SKU korekcija čuva napredak u `promotion_application_corrections`. Posao `resume_correction` koristi evidentirani rezultat nakon prekida. Poslije iscrpljenih pokušaja provjeriti otvoreni konflikt i posljednju grešku prije ponovnog zakazivanja istog ID-a korekcije.
- Puni i brzi kataloški prolaz koriste isti ključ zaključavanja prodavnice. Brzi prolaz kreće deset minuta prije posljednje uspješne gornje UTC granice. Prvo osvježavanje učitava sve dostupne stranice do početka prolaza. Greška na kasnijoj stranici ostavlja staru granicu, pa sljedeći prolaz ponavlja taj interval. Brisanje proizvoda i provjera nestalih varijanti imaju zasebne tokove.
- Omnibus pamti verziju relevantne promjene roditeljskog proizvoda. Dnevni puni prolaz ostaje provjera cijelog kataloga. Grupno čitanje istorije zadržava redoslijed, valutu i referentno vrijeme. Za pakete s više od 5.000 istorijskih redova servis koristi pojedinačna čitanja.

## Logovi i čišćenje

| Evidencija | Uslov | Rok |
|---|---|---|
| API statistika izvan workera | Jedan sažetak po zahtjevu i prodavnici | 14 dana |
| Operativne greške | Prvi primjer po API operaciji i statusu u kontekstu, plus zbirni brojači | 30 dana |
| Spori pozivi | Duže od dvije sekunde | 7 dana |
| Privremena dijagnostika | Odabrana prodavnica i operacija tokom najviše 30 minuta | 2 dana |
| Završeni poslovi | Uspješni / neuspjeli ili djelimični | 14 / 90 dana |
| Sync log | Bez grešaka / s greškama | 90 / 180 dana |
| Webhook | Obrađen / terminalno neuspješan | 90 dana od događaja / od terminalnog ishoda |

Worker uključuje API statistiku u zapis posla. Zbir obuhvata serijske i paralelne pozive, pokušaje, status, primljene bajtove i trajanje. Operativni direktorij mora biti izvan javnog korijena i dostupan PHP-u i cron korisniku. Rotacija mijenja datoteku pri 10 MiB ili promjeni dana, kompresuje zatvorenu datoteku i prilikom održavanja uklanja stare arhive prema budžetu od 100 MiB. Serverski access i PHP error log imaju zasebnu politiku hostinga.

1. Za svaku prodavnicu pokrenuti `php bin/maintain_logs.php --store=STORE --dry-run`. Izvještaj prikazuje broj i najstariji zapis po pravilu, neobrađene događaje i veličine poznatih starih datoteka. Cijene, istorija i evidencija odluka imaju zasebnu svrhu i ne ulaze u ovaj posao.
2. Nakon pregleda postaviti dnevni `php bin/maintain_logs.php --store=STORE --apply`. Jedan DELETE obrađuje do 1.000 redova. Budžet petlje brisanja je 30 sekundi po pokretanju. Početni zaostatak može zahtijevati više pokretanja. Provjeriti preostale brojeve i trajanje svakog prolaza. Stari nerazriješeni događaji ostaju za pregled. Na hostingu s HTTP cron-om, GET na `bin/maintain_logs.php?store=STORE` vraća probni izvještaj, a POST sa formularskim poljem `mode=apply` pokreće čišćenje. Oba poziva zahtijevaju `X-Cron-Token`.
3. Nakon zaustavljanja starih pisaca, opcija `--apply --archive-legacy` premješta prepoznate datoteke u privatni `legacy-quarantine`. Sadržaj može uključivati prethodno zapisane vjerodajnice. Ograničiti pristup, pregledati kopije, odrediti rok brisanja karantina i po potrebi zamijeniti ranije zabilježeni webhook ključ. Karantin je izuzet iz automatskog budžeta kako bi prethodne datoteke bile dostupne za pregled.
4. Za privremenu dijagnostiku postaviti `PROMORA_DEBUG_STORE`, `PROMORA_DEBUG_OPERATION` i UTC vrijeme `PROMORA_DEBUG_UNTIL`. Primjer operacije je `GET catalog/products/{id}`. Putanja se normalizuje i query parametri se izostavljaju. Ukloniti podešavanja nakon incidenta.

## Prihvat i proširenje

Na probnoj prodavnici uporediti dva jednaka kataloška prolaza, zatim promijeniti jednu cijenu i jednu zalihu. Bilježiti broj cjenovnih PUT zahtjeva, broj SQL upita, vršnu memoriju, medijan i p95 trajanja, najstariji spreman posao i razliku izračunate Omnibus reference. Ponovljeni usklađeni proizvod treba imati nula cjenovnih upisa. Promjena samo zalihe može promijeniti kvalifikaciju za promociju. Sam upis zalihe ne označava Omnibus cijenu izmijenjenom.

Sedam dana pratiti bajtove operativnih logova po danu, veličinu karantina, redove preostale za čišćenje, otvorene konflikte, djelimične poslove i 429 odgovore po prodavnici. Uporediti sa početnim mjerenjem pri sličnom obimu. Proširiti listu prodavnica nakon provjere cijena i potpunosti poslova. Vrijednosti u dva dokumenta s pregledom od 23. novembra predstavljaju polazni presjek.
