1) https://www.fontaine-a-eau.com
Envoi vers Dashboard Webylead :
URL à paramétrer : https://dashboard.webylead.com/api/717c5d43-26d3-47b9-9485-74c1dc3b9d76
Reformulation des slugs en titres de champs pertinent
---
Envoi vers Culligan :
URL à paramétrer : https://dashboard.webylead.com/api/3992ac79-0091-4bce-a596-5bbf9e8a1669
champs attendus :
fixe:
'salutation'   => 'Non précisé',
'utm_campaign' => 'ALB',
'utm_source'   => 'Affiliation',
'utm_medium'   => 'albFON',
'utm_adgroup'  => 'Fontaine',
'utm_term'     => 'ctatxt',
dynamiques
'lastname'     => trim(($prenom ? $prenom . ' ' : '') . $nom),
'company'      => $societe,
'email'        => $email,
'postal_code'  => $cp,
'city'         => $ville,
'phone'        => $telephone,
'question'     => $questionDefault,
-----
define('DEVIS_URL', 'https://dashboard.webylead.com/api/3992ac79-0091-4bce-a596-5bbf9e8a1669');
define('CULLIGAN_URL','https://go.pardot.com/l/1044463/2025-10-28/dmvrb');
define('CULLIGAN_ACTIVATION', True);
    /** @var string Chemin du fichier de logs JSONL */
    private const LEAD_BRIDGE_LOG = APPPATH . 'logs/lead_bridge_' . PHP_SAPI . '_' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '.log';

    public function devis_form()
    {
        $this->form_validation->set_rules('salaries', 'salaries', 'required');
        $this->form_validation->set_rules('visiteurs', 'visiteurs', 'required');
        $this->form_validation->set_rules('delai', 'delai', 'required');
        $this->form_validation->set_rules('nom', 'Nom', 'required');
        $this->form_validation->set_rules('prenom', 'prenom', 'required');
        $this->form_validation->set_rules('telephone', 'Téléphone', 'required');
        $this->form_validation->set_rules('email', 'Email', 'required|valid_email');
        $this->form_validation->set_rules('societe', 'societe', 'required');
        $this->form_validation->set_rules('cp', 'CP', 'required');

        if ($this->form_validation->run() !== true) {
            return;
        }

        $salaries  = $this->security->xss_clean($this->input->post('salaries'));
        $visiteurs = $this->security->xss_clean($this->input->post('visiteurs'));
        $delai     = $this->security->xss_clean($this->input->post('delai'));
        $nom       = $this->security->xss_clean($this->input->post('nom'));
        $prenom    = $this->security->xss_clean($this->input->post('prenom'));
        $telephone = $this->security->xss_clean($this->input->post('telephone'));
        $email     = $this->security->xss_clean($this->input->post('email'));
        $societe   = $this->security->xss_clean($this->input->post('societe'));
        $cp        = $this->security->xss_clean($this->input->post('cp'));

        // 1) Envoi interne
        $internalPayload = [
            'Nombre de salariés' => $salaries,
            'Visiteurs par jour' => $visiteurs,
            'Délai'              => $delai,
            'Nom'                => $nom,
            'Prénom'             => $prenom,
            'Téléphone'          => $telephone,
            'Email'              => $email,
            'Société'            => $societe,
            'CP'                 => $cp,
        ];
        $internalResult = $this->post_http(DEVIS_URL, $internalPayload, false);
		//$internalResult = [];
        $this->log_event('internal', $internalPayload, $internalResult);

        // 2) Envoi vers Culligan
		if(CULLIGAN_ACTIVATION){
			$landingUrl = CULLIGAN_URL;
			$ville = $this->resolve_city_from_cp_ign($cp);

			$questionDefault = sprintf(
				'Demande via partenaire. Effectif: %s, Visiteurs/jour: %s, Délai: %s.',
				$salaries ?: 'Non précisé',
				$visiteurs ?: 'Non précisé',
				$delai ?: 'Non précisé'
			);

			$culliganPayload = [
				'salutation'   => 'Non précisé',
				'lastname'     => trim(($prenom ? $prenom . ' ' : '') . $nom),
				'company'      => $societe,
				'email'        => $email,
				'postal_code'  => $cp,
				'city'         => $ville,
				'phone'        => $telephone,
				'utm_campaign' => 'ALB',
				'utm_source'   => 'Affiliation',
				'utm_medium'   => 'albFON',
				'utm_adgroup'  => 'Fontaine',
				'utm_term'     => 'ctatxt',
				'question'     => $questionDefault,
			];

			$culliganResult = $this->post_http($landingUrl, $culliganPayload, true);
			$this->log_event('culligan', $culliganPayload, $culliganResult);
		}

        // Réponse front (ne casse pas l’existant)
        echo 'OK';
    }

    /** Poste un formulaire x-www-form-urlencoded et retourne un tableau de résultat. */
    private function post_http(string $url, array $payload, bool $withReferer): array
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'LeadBridge/1.0 (+CI PHP7)',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8'],
        ];
        if ($withReferer) {
            $opts[CURLOPT_REFERER] = base_url();
        }
        curl_setopt_array($ch, $opts);

        $body       = curl_exec($ch);
        $curlErrNo  = curl_errno($ch);
        $curlErr    = $curlErrNo ? curl_error($ch) : null;
        $info       = curl_getinfo($ch);
        curl_close($ch);

        $httpCode   = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $ok         = ($curlErrNo === 0 && $httpCode >= 200 && $httpCode < 300);

        return [
            'ok'          => $ok,
            'http_code'   => $httpCode,
            'curl_errno'  => $curlErrNo,
            'curl_error'  => $curlErr,
            'total_time'  => $info['total_time'] ?? null,
            'effective_url'=> $info['url'] ?? $url,
            'preview'     => $this->preview_response($body),
        ];
    }

	/** Résout la ville via IGN apicarto (fallback: 'Non précisée'). */
	private function resolve_city_from_cp_ign(string $cp): string
	{
		$cp = trim($cp);
		if ($cp === '' || !preg_match('/^\d{5}$/', $cp)) {
			return 'Non précisée';
		}

		$url = 'https://apicarto.ign.fr/api/codes-postaux/communes/' . rawurlencode($cp);

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_HTTPHEADER     => ['Accept: application/json'],
		]);
		$resp  = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		curl_close($ch);

		if ($errno !== 0 || !$resp) {
			return 'Non précisée';
		}

		$data = json_decode($resp, true);
		
		//$this->log_event('resolve_city', ['cp' => $cp], ['response' => $data]);

		if (!is_array($data) || empty($data[0])) {
			return 'Non précisée';
		}
		
		$nom = $data[0]['nomCommune'] ?? '';
		$lib = $data[0]['libelleAcheminement'] ?? '';
		return $nom ?: ($lib ?: 'Non précisée');
	}


    /** Ecrit une ligne JSON dans un fichier de logs (JSON Lines). */
    private function log_event(string $target, array $payload, array $result): void
	{
		$record = [
			'ts'      => date('c'),
			'target'  => $target,
			'payload' => $payload,
			'result'  => $result,
		];

		$line = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
		@file_put_contents(self::LEAD_BRIDGE_LOG, $line, FILE_APPEND | LOCK_EX);
		if (function_exists('log_message')) {
			@log_message('info', '[lead-bridge] ' . $line);
		}
	}

    /** Retourne un aperçu propre (300 chars max) du HTML/JSON reçu. */
    private function preview_response(?string $body): string
    {
        if ($body === null || $body === '') {
            return '';
        }
        $str = strip_tags($body);
        $str = preg_replace('/\s+/', ' ', $str);
        if (mb_strlen($str) > 300) {
            $str = mb_substr($str, 0, 300) . '…';
        }
        return $str;
    }

    /** Masque le milieu d’une chaîne (email/téléphone) pour le log. */
    private function mask_middle(string $value): string
    {
        $len = mb_strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        $start = mb_substr($value, 0, 2);
        $end   = mb_substr($value, -2);
        return $start . str_repeat('*', max(1, $len - 4)) . $end;
    }


2) https://www.distributeurautomatique.net
Envoi vers Dashboard Webylead :
URL à paramétrer : https://dashboard.webylead.com/api/0159cd0a-2bbf-4e6c-b749-aaf2373227bf
Reformulation des slugs en titres de champs pertinent
---
Envoi vers Applicatif Webylead :
URL à paramétrer : http://app.webylead.com/prospect/devis-distributeur
champs attendus :
nom
prenom
cp
email
telephone
societe
salaries
visiteurs
locaux
-----
define('DEVIS_URL', 'https://dashboard.webylead.com/api/0159cd0a-2bbf-4e6c-b749-aaf2373227bf');
define('BRIDGE_URL', 'http://app.webylead.com/prospect/devis-distributeur');
	/*------------- devis form -------------*/

	public function devis_form()
	{
		$this->form_validation->set_rules('salaries', 'salaries', 'required');
		$this->form_validation->set_rules('visiteurs', 'visiteurs', 'required');
		$this->form_validation->set_rules('locaux', 'locaux', 'required');
		$this->form_validation->set_rules('nom', 'Nom', 'required');
		$this->form_validation->set_rules('prenom', 'prenom', 'required');
		$this->form_validation->set_rules('telephone', 'Téléphone', 'required');
		$this->form_validation->set_rules('email', 'Email', 'required|valid_email');
		$this->form_validation->set_rules('societe', 'societe', 'required');
		$this->form_validation->set_rules('cp', 'CP', 'required');

		if ($this->form_validation->run() === TRUE)
		{
		 $postData = [
			 "Nombre de salariés" => $this->security->xss_clean($this->input->post('salaries')),
			 "Nombre de visiteurs" => $this->security->xss_clean($this->input->post('visiteurs')),
			 "Type de locaux" => $this->security->xss_clean($this->input->post('locaux')),
			 "Nom" => $this->security->xss_clean($this->input->post('nom')),
			 "Prénom" => $this->security->xss_clean($this->input->post('prenom')),
			 "Téléphone" => $this->security->xss_clean($this->input->post('telephone')),
			 "Email" => $this->security->xss_clean($this->input->post('email')),
			 "Société" => $this->security->xss_clean($this->input->post('societe')),
			 "CP" => $this->security->xss_clean($this->input->post('cp'))
		 ];

			$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL, DEVIS_URL);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch,CURLOPT_HEADER, true);
			curl_setopt($ch,CURLOPT_POST, true);
			curl_setopt($ch,CURLOPT_POSTFIELDS, $postData);
			curl_exec($ch);

			if (curl_errno($ch)) {
				echo curl_error($ch);
			}else{
				echo 'OK';
			}
			curl_close($ch);

		 $bridgeData = [
			 "formulaire" => "devis-distributeur",
			 "domaine" => "distributeurautomatique.net",
			 "salaries" => $this->security->xss_clean($this->input->post('salaries')),
			 "visiteurs" => $this->security->xss_clean($this->input->post('visiteurs')),
			 "locaux" => $this->security->xss_clean($this->input->post('locaux')),
			 "nom" => $this->security->xss_clean($this->input->post('nom')),
			 "prenom" => $this->security->xss_clean($this->input->post('prenom')),
			 "telephone" => $this->security->xss_clean($this->input->post('telephone')),
			 "email" => $this->security->xss_clean($this->input->post('email')),
			 "societe" => $this->security->xss_clean($this->input->post('societe')),
			 "cp" => $this->security->xss_clean($this->input->post('cp'))
		 ];
			$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL, BRIDGE_URL);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch,CURLOPT_HEADER, true);
			curl_setopt($ch,CURLOPT_POST, true);
			curl_setopt($ch,CURLOPT_POSTFIELDS, $bridgeData);
			curl_setopt($ch, CURLOPT_TIMEOUT, 1);
			curl_exec($ch);
			curl_close($ch);
		}
	}


3) https://www.photocopieuse.net
Envoi vers Dashboard Webylead :
URL à paramétrer : https://dashboard.webylead.com/api/717c5d43-26d3-47b9-9485-74c1dc3b9d76
Reformulation des slugs en titres de champs pertinent
---
define('DEVIS_URL', 'https://dashboard.webylead.com/api/717c5d43-26d3-47b9-9485-74c1dc3b9d76');
		public function devis_form()
		{
			$this->form_validation->set_rules('impressions', 'impressions', 'required');
			$this->form_validation->set_rules('format', 'format', 'required');
			$this->form_validation->set_rules('effectif', 'effectif', 'required');
			$this->form_validation->set_rules('postes', 'postes', 'required');
			$this->form_validation->set_rules('nom', 'Nom', 'required');
			$this->form_validation->set_rules('prenom', 'prenom', 'required');
			$this->form_validation->set_rules('telephone', 'Téléphone', 'required');
			$this->form_validation->set_rules('email', 'Email', 'required|valid_email');
			$this->form_validation->set_rules('societe', 'societe', 'required');
			$this->form_validation->set_rules('cp', 'CP', 'required');

			if ($this->form_validation->run() === TRUE)
			{
			 $postData = [
				 "Volume d'impressions " => $this->security->xss_clean($this->input->post('impressions')),
				 "Format d'impression" => $this->security->xss_clean($this->input->post('format')),
				 "Effectif" => $this->security->xss_clean($this->input->post('effectif')),
				 "Postes informatiques" => $this->security->xss_clean($this->input->post('postes')),
				 "Nom" => $this->security->xss_clean($this->input->post('nom')),
				 "Prénom" => $this->security->xss_clean($this->input->post('prenom')),
				 "Téléphone" => $this->security->xss_clean($this->input->post('telephone')),
				 "Email" => $this->security->xss_clean($this->input->post('email')),
				 "Société" => $this->security->xss_clean($this->input->post('societe')),
				 "CP" => $this->security->xss_clean($this->input->post('cp'))
			 ];

				$ch = curl_init();
				curl_setopt($ch,CURLOPT_URL, DEVIS_URL);
				curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch,CURLOPT_HEADER, true);
				curl_setopt($ch,CURLOPT_POST, true);
				curl_setopt($ch,CURLOPT_POSTFIELDS, $postData);
				curl_exec($ch);

				if (curl_errno($ch)) {
					echo curl_error($ch);
				}else{
					echo 'OK';
				}
				curl_close($ch);
			}
		}


4) https://www.autolaveuse.fr
Envoi vers Dashboard Webylead :
URL à paramétrer : https://dashboard.webylead.com/api/c1a467db-aeca-4e8c-81ba-64278cebb372
Reformulation des slugs en titres de champs pertinent
---
define('DEVIS_URL', 'https://dashboard.webylead.com/api/c1a467db-aeca-4e8c-81ba-64278cebb372');
	public function devis_form()
	{
		$this->form_validation->set_rules('type', 'type', 'required');
		$this->form_validation->set_rules('superficie', 'visiteurs', 'required');
		$this->form_validation->set_rules('secteur', 'secteur', 'required');
		$this->form_validation->set_rules('nom', 'Nom', 'required');
		$this->form_validation->set_rules('prenom', 'prenom', 'required');
		$this->form_validation->set_rules('telephone', 'Téléphone', 'required');
		$this->form_validation->set_rules('email', 'Email', 'required|valid_email');
		$this->form_validation->set_rules('societe', 'societe', 'required');
		$this->form_validation->set_rules('cp', 'CP', 'required');

		if ($this->form_validation->run() === TRUE)
		{
		 $postData = [
			 "Type de machine" => $this->security->xss_clean($this->input->post('type')),
			 "Superficie des locaux" => $this->security->xss_clean($this->input->post('superficie')),
			 "Secteur d'activité" => $this->security->xss_clean($this->input->post('secteur')),
			 "Nom" => $this->security->xss_clean($this->input->post('nom')),
			 "Prénom" => $this->security->xss_clean($this->input->post('prenom')),
			 "Téléphone" => $this->security->xss_clean($this->input->post('telephone')),
			 "Email" => $this->security->xss_clean($this->input->post('email')),
			 "Société" => $this->security->xss_clean($this->input->post('societe')),
			 "CP" => $this->security->xss_clean($this->input->post('cp'))
		 ];

			$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL, DEVIS_URL);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch,CURLOPT_HEADER, true);
			curl_setopt($ch,CURLOPT_POST, true);
			curl_setopt($ch,CURLOPT_POSTFIELDS, $postData);
			curl_exec($ch);

			if (curl_errno($ch)) {
				echo curl_error($ch);
			}else{
				echo 'OK';
			}
			curl_close($ch);
		}
	}


5) https://www.telesurveillance.eu
Envoi vers Dashboard Webylead :
URL à paramétrer : https://dashboard.webylead.com/api/959db948-e836-44c9-aa3b-b32024ad0a1e
Reformulation des slugs en titres de champs pertinent
---
Envoi vers Applicatif Webylead :
URL à paramétrer : http://app.webylead.com/prospect/devis-telesurveillance
champs attendus :
locaux
cameras
surface
installation
creation
cp
nom
prenom
societe
email
telephone
-----
define('DEVIS_URL', 'https://dashboard.webylead.com/api/959db948-e836-44c9-aa3b-b32024ad0a1e');
define('BRIDGE_URL', 'http://app.webylead.com/prospect/devis-telesurveillance');
public function devis_form()
	{
		$this->form_validation->set_rules('locaux', 'Type de locaux', 'required');
		$this->form_validation->set_rules('cameras', 'Nombre de caméras', 'required');
		$this->form_validation->set_rules('surface', 'Surface des locaux', 'required');
		$this->form_validation->set_rules('installation', 'Votre installation', 'required');
		$this->form_validation->set_rules('creation', 'Société créée', 'required');
		$this->form_validation->set_rules('nom', 'Nom', 'required');
		$this->form_validation->set_rules('prenom', 'Prénom', 'required');
		$this->form_validation->set_rules('societe', 'Société', 'required');
		$this->form_validation->set_rules('telephone', 'Téléphone', 'required');
		$this->form_validation->set_rules('email', 'Email', 'required|valid_email');
		$this->form_validation->set_rules('cp', 'Code postal', 'required');

		if ($this->form_validation->run() === TRUE)
		{

			$postData = [
				'Type de locaux' => $this->security->xss_clean($this->input->post('locaux')),
				'Nombre de caméras' => $this->security->xss_clean($this->input->post('cameras')),
				'Surface à sécuriser' => $this->security->xss_clean($this->input->post('surface')),
				"Type d'installation" => $this->security->xss_clean($this->input->post('installation')),
				"Création de société" => $this->security->xss_clean($this->input->post('creation')),
				'Nom' => $this->security->xss_clean($this->input->post('nom')),
				'Prenom' => $this->security->xss_clean($this->input->post('prenom')),
				'Nom de societe' => $this->security->xss_clean($this->input->post('societe')),
				'Telephone' => $this->security->xss_clean($this->input->post('telephone')),
				'Email' => $this->security->xss_clean($this->input->post('email')),
				'Code postal' => $this->security->xss_clean($this->input->post('cp'))
			];

			$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL, DEVIS_URL);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
			// companeo
			// curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', DEVIS_BEARER));
			curl_setopt($ch,CURLOPT_HEADER, true);
			curl_setopt($ch,CURLOPT_POST, true);
			curl_setopt($ch,CURLOPT_POSTFIELDS, $postData);
			curl_exec($ch);

			if (curl_errno($ch)) {
				// echo '{"response":"'.curl_error($ch).'"}';
				echo '{"response":"error"}';
			}else{
				echo '{"response":"success"}';
			}
			curl_close($ch);
			
			/* BEGIN : BRIDGE TO APP.WEBYLEAD.COM */
			$bridgeData = [
				"formulaire" => "devis-telesurveillance",
				"domaine" => "telesurveillance.eu",
				'locaux' => $this->security->xss_clean($this->input->post('locaux')),
				'cameras' => $this->security->xss_clean($this->input->post('cameras')),
				'surface' => $this->security->xss_clean($this->input->post('surface')),
				"installation" => $this->security->xss_clean($this->input->post('installation')),
				"creation" => $this->security->xss_clean($this->input->post('creation')),
				'nom' => $this->security->xss_clean($this->input->post('nom')),
				'prenom' => $this->security->xss_clean($this->input->post('prenom')),
				'societe' => $this->security->xss_clean($this->input->post('societe')),
				'telephone' => $this->security->xss_clean($this->input->post('telephone')),
				'email' => $this->security->xss_clean($this->input->post('email')),
				'cp' => $this->security->xss_clean($this->input->post('cp'))
			];
			$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL, BRIDGE_URL);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch,CURLOPT_HEADER, true);
			curl_setopt($ch,CURLOPT_POST, true);
			curl_setopt($ch,CURLOPT_POSTFIELDS, $bridgeData);
			curl_setopt($ch, CURLOPT_TIMEOUT, 1);
			curl_exec($ch);
			curl_close($ch);
			/* END : BRIDGE TO APP.WEBYLEAD.COM */
		}
	}


6) https://www.entreprisenettoyage.net
Envoi vers Applicatif Webylead :
URL à paramétrer : http://app.webylead.com/prospect/devis-nettoyage
champs attendus :
prestation
frequence
surface
nom
telephone
cp
email
fonction
entreprise
-----
if(strlen(filter_input(INPUT_POST, 'formulaire'))>0){
	$formulaire = filter_input(INPUT_POST, 'formulaire');
}else{
	$formulaire = FALSE;
}
if(strlen(filter_input(INPUT_POST, 'domaine'))>0){
	$domaine = filter_input(INPUT_POST, 'domaine');
	echo($domaine);
}else{
	$domaine = FALSE;
}
if($formulaire&&$domaine){
	$ch = curl_init();

	curl_setopt($ch,CURLOPT_URL,'http://app.webylead.com/prospect/'.$formulaire);
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
	curl_setopt($ch,CURLOPT_HEADER, true);
	curl_setopt($ch,CURLOPT_POST, true);
	curl_setopt($ch,CURLOPT_POSTFIELDS, $_POST);

	curl_exec($ch);
	if (curl_errno($ch)) {
		echo curl_error($ch);
	}else{
		echo 'OK';
	}
	curl_close($ch);
	print_r($_POST);
}
?>