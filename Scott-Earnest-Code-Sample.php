<?php

/**
 * @file
 *
 * bic.avail.inc
 *
 * This file contains availability functions fro properties,
 * including admin, batch updates, and output.
 * Depends on the "availability_calendars" contributed module
 *
 */ 

 
/**
 * admin interface for availability
 * shows when last automatic update was run
 * allows user to update all properties avail or a specific property
 */
function bic_avail() {

	$output = '';
	$output .= '<h2>Property Availability</h2>';
	$output .= 'Last Automatic Run: ' . format_date(variable_get('bic_rates_avail_cron', ''), 'long') . '<br />';
	$output .= '<h4><a href="/admin/properties/avail/update">click here to update all availability</a></h4>';
	$output .= '<strong>to update availability for an individual property click the name of the property below:</strong><br /><br />';
	// get the property ids
	$results = bic_property_id_results();	
	foreach($results AS $prop) {
		$output .= '<a href="/admin/properties/avail/update?nid=' . $prop->nid . '">' . $prop->title . '</a><br />';
	}
	return $output;

}	//  function bic_avail() {


/**
 * batch process for updating availability
 * can potentially be a lengthy process
 * batch is necessary to avoid timeouts
 */
function bic_avail_update() {

  $batch = array(
    'title' => t('Updating Availability ...'),
    'operations' => array(),
    'init_message' => t('Fetching Properties...'),
    'progress_message' => t('Processed @current out of @total.'),
    'error_message' => t('An error occurred during processing'),
    'progressive' => FALSE,
		'file' => drupal_get_path('module', 'bic') . '/bic.avail.inc',
  );

	drupal_set_message('Availability updated for the following properties:');

	$results = bic_property_id_results();	
	foreach ( $results AS $prop ) {
		$addProp = TRUE;
		if ( !empty($_GET['nid']) && $prop->nid != $_GET['nid'] ) {
			$addProp = FALSE;
		}
		if ($addProp) {
		  $batch['operations'][] = array('bic_avail_update_property', array($prop->entity_id, $prop->field_propertyid_value, $prop->title));
		}	// if ($addProp) {
	}	// foreach ( $results AS $prop ) {

  batch_set($batch);
  batch_process('admin/properties/avail');

}	// function bic_avail_update() {


/**
 * fetches availability from SOAP request and updates a single property
 * uses the Barefoot webservice to get booked dates
 */ 
function bic_avail_update_property($nid, $field_propertyid_value, $title, $context) {

	// grabs the availability calendar ID
	$queryString = "SELECT field_property_availability_cid FROM field_data_field_property_availability WHERE entity_id = :nid AND field_property_availability_enabled = 1 ORDER BY field_property_availability_cid LIMIT 1";
	$queryValues = array('nid' => $nid);
	$cid = db_query($queryString, $queryValues)->fetchField();
	
	// check that the availability calendar is enabled for this property
	if ( !empty($cid) ) {

		$creds = bic_barefoot_creds();
		$webservice = new SoapClient($creds['url']);
		$startdate = date("m/d/Y", strtotime("-3 months"));
		$enddate = date("m/d/Y", strtotime("+2 years"));
		
		// call the method to retrieve the availability
		$avail = $webservice->GetAvailableBookingPeriods(array(
			'username' => $creds['username'],
			'password' => $creds['password'],
			'barefootAccount' => $creds['barefootAccount'],
			'date1' => $startdate,
			'date2' => $enddate,
			'propertyId' => $field_propertyid_value,
		));
	
		// load result into an XML object
		$avail_xml=simplexml_load_string($avail->GetAvailableBookingPeriodsResult->any);
	
		// clear existing availability
		$deleted = db_delete('availability_calendar_availability')
		  ->condition('cid', $cid)
		  ->execute();
	
		$date_last = $startdate;
		
		// loop through each booking period result
		foreach ( $avail_xml->Property->AvailableBookingPeriods AS $availItem ) {

			$date1 = (string) $availItem->Date1;
			$date2 = (string) $availItem->Date2;
			// indicates booked dates - set status to 3 (booked)
			if ( date('Ymd', strtotime($date1)) > date('Ymd', strtotime($date_last)) ) {
				$date1_booked = date('m/d/Y', strtotime($date_last . '+1 day'));
				$date2_booked = date('m/d/Y', strtotime($date1 . '-1 day'));
				bic_avail_update_daterange($cid, 3, $date1_booked, $date2_booked);
			}	// if ( date('Ymd', strtotime($date1)) > date('Ymd', strtotime($date_last)) {
			// if date is not booked, its available, set status to 2 (available)
			bic_avail_update_daterange($cid, 2, $date1, $date2);
			$date_last = $date2;
			
		} // foreach ( $avail_xml->Property->PropertyRates AS $availItem ) {

		$message = $title;
			
	}	// if ( !empty($cid) ) {
	
	else {
		$message = $title . ' - AVAILABILITY CALENDAR NOT ENABLED';
	}	// else {

	// return results and messages
	drupal_set_message($message);
  $context['message'] = $message;
	watchdog('bic', $message);

}	// function bic_avail_update() {


/**
 * helper function to set a range of dates to a status
 * takes into consideration overnight stay
 * if a person leaves in the am, the pm should be available to book
 */
function bic_avail_update_daterange($cid, $sid, $date1, $date2, $overnight = FALSE) {

	$datetime1 = date_create($date1);
	// if avail is overnight, end date should be available
	if ( $overnight ) {
		$datetime2 = date_create($date2 . '-1 day');
	}
	else {
		$datetime2 = date_create($date2);
	}

	$interval = date_diff($datetime1, $datetime2);

	// returns the total number of days between date1 and date2
	$day_diff = $interval->format('%a');
	
	for ( $index = 0; $index <= $day_diff; $index++ ) {
	
		$datenow = date("Y-m-d", strtotime($date1 .  "+" . $index . " days"));

		try {
			db_insert('availability_calendar_availability')
				->fields(array(
				  'cid' => $cid,
				  'date' => $datenow,
				  'sid' => $sid,
					))
				->execute();
		}
		
		catch(Exception $e)
		{
			$message = $e->getMessage();
		  drupal_set_message('WARNING: ' . $message);
		}

	}	// for ( $index = 0; $index <= $day_diff; $index++ ) {

}	// function bic_avail_update_daterange($cid, $sid, $date1, $date2) {


/**
 * front side for the availability
 * shows the availability calendar if enabled
 * other choices for out put are a "Book Direct" link or "Contact Owner" popup
 */ 
function bic_avail_view() {

	$output = '';

	if ($property = menu_get_object()) {
		
		$show_avail = FALSE;
		if ( !empty($property->field_property_availability['und'][0]['enabled']) && ($property->field_property_availability['und'][0]['enabled'] == 1) ) {
			$cid = $property->field_property_availability['und'][0]['cid'];
			$today = date("Y-m-d");
			$queryString = "SELECT date FROM availability_calendar_availability WHERE sid = 3 AND cid = " . $cid . " AND date >= '" . $today . "' LIMIT 1;";
			$result = db_query($queryString);
			if ( $result->rowCount() ) {
				$show_avail = TRUE;
			}
		}
	
		if ( $show_avail ) {
			$key = module_invoke('availability_calendar', 'block_view', 'key');
			$output .= render($key['content']);
			$display = array(
				'label'=>'hidden', 
			  'type' => 'availability_calendar', 
			  'settings'=>array('show_number_of_months' => 12, 'first_day_of_week' => 7, 'show_split_day' => 1),
			);
			// this is the actual calendar view
			$avail = field_view_field('node', $property, 'field_property_availability', $display);
			$output .= render($avail);
		}	// if ( $show_avail )
		
		// book direct link
		else if ( !empty($property->field_property_book_link['und'][0]['url']) ) {
		  $output .= '<a href="' . $property->field_property_book_link['und'][0]['url'] . '"';
		  $output .= ' class="a-button orange book-link" target="_blank">';
		  $output .= 'Book Direct</a><br /><br />';
		} // if ( !empty($property_node->field_property_book_link['und'][0]) ) {
		
		// get a quote popup
		else {
			$account = user_load($property->uid);
      $output .= '<span class="property-quote"><a href="/booking-form-popup/' . $property->nid . '?width=500&height=735&iframe=true"';
      $output .= 'class="a-button orange owner-contact lead_activity colorbox-load" data-property="' . $property->title . '"';
      $output .= 'data-account="' . $account->field_account_name['und'][0]['safe_value'] . '" data-lead_activity_type="details"';
      $output .= 'data-company_id="' . $account->uid . '">Contact Owner</a></span>';
      // contact owner button
			$output .= bic_contact_owner_button($account, 'contactinfoAvail');
		}
		
	}	// if ($property = menu_get_object()) {

	return $output;

}	// function bic_avail_view() {
