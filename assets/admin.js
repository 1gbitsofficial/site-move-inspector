( function () {
	'use strict';

	const config = window.ogsmiAdmin || {};
	const form = document.getElementById( 'ogsmi-scan-form' );
	const startButton = document.getElementById( 'ogsmi-start' );
	const cancelButton = document.getElementById( 'ogsmi-cancel' );
	const progress = document.getElementById( 'ogsmi-progress' );
	const progressMessage = document.getElementById( 'ogsmi-progress-message' );
	const progressDetail = document.getElementById( 'ogsmi-progress-detail' );
	const errorBox = document.getElementById( 'ogsmi-error' );

	if ( ! form || ! config.restRoot || ! config.nonce ) {
		return;
	}

	let activeJob = config.activeJob || '';
	let canceled = false;
	let stepping = false;

	const strings = config.strings || {};

	function endpoint( path ) {
		return config.restRoot.replace( /\/+$/, '' ) + path;
	}

	async function request( path, method, body ) {
		const options = {
			method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
		};

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		const response = await window.fetch( endpoint( path ), options );
		let data = {};

		try {
			data = await response.json();
		} catch ( error ) {
			data = {};
		}

		if ( ! response.ok ) {
			const message = data && data.message ? data.message : strings.genericError;
			throw new Error( message );
		}

		return data;
	}

	function setBusy( busy ) {
		startButton.disabled = busy;
		form.querySelectorAll( 'fieldset' ).forEach( function ( fieldset ) {
			fieldset.disabled = busy;
		} );
		cancelButton.hidden = ! busy;
		progress.hidden = ! busy;
	}

	function showMessage( message, detail ) {
		progressMessage.textContent = message || '';
		progressDetail.textContent = detail || '';
	}

	function showError( error ) {
		setBusy( false );
		activeJob = '';
		stepping = false;
		errorBox.hidden = false;
		errorBox.querySelector( 'p' ).textContent =
			error && error.message ? error.message : strings.genericError;
	}

	function clearError() {
		errorBox.hidden = true;
		errorBox.querySelector( 'p' ).textContent = '';
	}

	function formatBytes( bytes ) {
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let value = Number( bytes ) || 0;
		let unit = 0;

		while ( value >= 1024 && unit < units.length - 1 ) {
			value /= 1024;
			unit += 1;
		}

		return value.toFixed( unit === 0 ? 0 : 1 ) + ' ' + units[ unit ];
	}

	function formatTemplate( template, value ) {
		return String( template || '%s' ).replace( '%s', value );
	}

	function updateProgress( result ) {
		const counters = result && result.progress ? result.progress : {};
		const fileText = formatTemplate(
			strings.filesScanned,
			new Intl.NumberFormat().format( Number( counters.files ) || 0 )
		);
		const byteText = formatTemplate(
			strings.bytesScanned,
			formatBytes( counters.bytes )
		);
		showMessage( strings.scanning, fileText + ' · ' + byteText );
	}

	async function stepScan() {
		if ( ! activeJob || canceled || stepping ) {
			return;
		}

		stepping = true;

		try {
			const result = await request(
				'/scan/' + encodeURIComponent( activeJob ) + '/step',
				'POST'
			);
			if ( canceled || ! activeJob ) {
				stepping = false;
				return;
			}
			updateProgress( result );
			stepping = false;

			if ( result.complete ) {
				showMessage( strings.complete, '' );
				window.setTimeout( function () {
					window.location.reload();
				}, 250 );
				return;
			}

			window.setTimeout( stepScan, 40 );
		} catch ( error ) {
			showError( error );
		}
	}

	form.addEventListener( 'submit', async function ( event ) {
		event.preventDefault();
		if ( activeJob ) {
			return;
		}

		clearError();
		canceled = false;

		const formData = new window.FormData( form );
		const payload = {
			target_php: formData.get( 'target_php' ) || '',
			target_database_engine: formData.get( 'target_database_engine' ) || '',
			target_database_version: formData.get( 'target_database_version' ) || '',
			target_disk_gb: formData.get( 'target_disk_gb' ) || '',
			target_multisite: formData.get( 'target_multisite' ) || 'unknown',
			self_test: formData.has( 'self_test' ),
		};

		setBusy( true );
		showMessage( strings.starting, '' );

		try {
			const result = await request( '/scan', 'POST', payload );
			activeJob = result.job_id;
			updateProgress( result );
			stepScan();
		} catch ( error ) {
			showError( error );
		}
	} );

	cancelButton.addEventListener( 'click', async function () {
		if ( ! activeJob ) {
			return;
		}

		canceled = true;
		cancelButton.disabled = true;
		showMessage( strings.canceling, '' );

		try {
			await request( '/scan/' + encodeURIComponent( activeJob ), 'DELETE' );
			activeJob = '';
			stepping = false;
			setBusy( false );
			cancelButton.disabled = false;
			progress.hidden = false;
			showMessage( strings.canceled, '' );
		} catch ( error ) {
			cancelButton.disabled = false;
			showError( error );
		}
	} );

	if ( activeJob ) {
		setBusy( true );
		showMessage( strings.scanning, '' );
		stepScan();
	}
}() );
