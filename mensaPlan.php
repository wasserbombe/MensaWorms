<?php
	setlocale(LC_TIME, "de_DE");
	
	$res = array(
		'request' => $_REQUEST,
		'code' => 400,
		'data' => array(),
		'errors' => array()
	);

    if (!empty($_REQUEST["format"])){
		$_REQUEST["format"] = strtolower($_REQUEST["format"]);

		// getting plan from website
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'User-Agent: Mensaplan2Telegram Script',
		));
		curl_setopt($ch, CURLOPT_URL, "http://www.studierendenwerk-vorderpfalz.de/home/speiseplaene/speiseplaene/worms-mensa-wochenplan-aktuell.html");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		
		$raw = curl_exec ($ch);
		
		curl_close ($ch);

		if (200 == 200){
			$doc = new DOMDocument();
			$doc->loadHTML($raw);

			$json = array(
				"counters" => array(),
				"additives" => array(),
				"data" => array()
			);

			// search weektable + additives
			$weektable = $additivetable = null;
			foreach ($doc->getElementsByTagName("table") as $table){
				if (hasClass($table, "weektable")){
					$weektable = $table; 
				} elseif (hasClass($table, "additives")){
					$additivetable = $table;
				}
			}

			if ($additivetable){
				$ps = $additivetable->getElementsByTagName("p");
				if ($ps[0]){
					preg_match_all("~([0-9]+|[a-z]+)=([^,]+)~i", $ps[0]->textContent, $matches);
					foreach ($matches[1] as $i => $id){
						$json["additives"][] = array(
							"id" => $id,
							"description" => ucfirst($matches[2][$i])
						);
					}
				}
			}

			if ($weektable){
				// get counter names
				$c = 0; 
				foreach ($weektable->getElementsByTagName("th") as $th){
					if (hasClass($th, "menuTitle")){
						$json["counters"][] = array("id" => $c++, "name" => $th->textContent);
					}
				}

				// get days/table rows
				foreach ($weektable->getElementsByTagName("tr") as $t => $tr){
					if ($t == 0) continue;

					$this_day = array(
						"date" => null,
						"meals" => array()
					);

					foreach ($tr->getElementsByTagName("td") as $c => $td){
						if (hasClass($td, "day")){
							preg_match("~([0-9]{2})\.([0-9]{2})\.([0-9]{4})~", $td->textContent, $matches);
							if ($matches){
								$this_day["date"] = date("Y-m-d", strtotime($matches[0]));
							}
						} else {
							$this_meal = array(
								"counter" => $c - 1,
								"things" => array(),
								"allergy_information" => array()
							);

							$lines = explode("\r\n",$td->textContent);
							foreach ($lines as $line){
								if (strlen($line) <= 3) continue;
								if (preg_match("~Dispo-ID: (.+)$~", $line, $matches)){
									$this_meal["dispoID"] = $matches[1];
								} elseif (!preg_match("~Dispo-ID~", $line, $matches)){
									$thing = $line; 

									// parsing allergies
									preg_match_all("~\(([^\)]+)\)~", $thing, $matches);
									foreach ($matches[1] as $allergy_stoffe){
										$stoffe = explode(",", $allergy_stoffe);
										foreach ($stoffe as $stoff){
											if (!in_array($stoff, $this_meal["allergy_information"])) $this_meal["allergy_information"][] = $stoff; 
										}
									}

									// remove allergy information
									$thing = preg_replace("~\([^\)]+\)+~i", "", $thing);

									// remove blanks on start and end of each line
									$thing = preg_replace("~^ +~i", "", preg_replace("~ +$~i", "", $thing));

									// replace duplicate blanks with commata
									$thing = preg_replace("~ {2,}~i", ", ", $thing);
									
									if (strlen($thing) > 0) $this_meal["things"][] = $thing;
								}
							}

							if (isset($json["counters"][$this_meal["counter"]]) && sizeof($this_meal["things"])){
								$this_day["meals"][] = $this_meal;
							}
						}
					}

					$json["data"][] = $this_day;
				}

				if ($_REQUEST["format"] == "json"){
					$res["data"] = $json;
				} elseif ($_REQUEST["format"] == "tgmessage"){
					// TODO
				} else {
					$res["errors"][] = "Unknown format: '".$_REQUEST["format"]."'!";
					$res["code"] = 400;
				}

			} else {
				$res["errors"][] = "Cannot find weektable!";
				$res["code"] = 500;	
			}
		} else {
			$res["errors"][] = "Error communicating with website!";
			$res["code"] = 500;	
		}
	} else {
		$res["errors"][] = "Missing required parameter 'format'!";
		$res["code"] = 400;
	}
	http_response_code($res["code"]);
	echo json_encode($res, JSON_PRETTY_PRINT);

	//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	// HELPER FUNCTIONS //////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	function hasClass($DOMElement, $classname){
		if (!$DOMElement->getAttribute("class")) return false;
		if (preg_match("~(^| )".$classname."( |$)~", $DOMElement->getAttribute("class"))) return true;
		return false;
	}
?>