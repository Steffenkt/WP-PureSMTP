/**
 * WP PureSMTP – Statistics charts (D3.js v7, fully local).
 *
 * Renders four visualisations from the data injected via wp_localize_script
 * (window.pureSMTPStats):
 *   - Daily stacked bar chart (sent + failed)
 *   - Hourly distribution line chart
 *   - Top recipients horizontal bar chart
 *   - Top source plugins horizontal bar chart
 */
( function () {
	'use strict';

	if ( typeof window.d3 === 'undefined' || typeof window.pureSMTPStats === 'undefined' ) {
		return;
	}

	var d3    = window.d3;
	var data  = window.pureSMTPStats.data || {};
	var i18n  = window.pureSMTPStats.i18n || {};

	var COLORS = {
		sent:   '#16a34a',
		failed: '#dc2626',
		bar:    '#3b82f6',
		bar2:   '#6366f1',
		grid:   '#e2e8f2',
		text:   '#526070',
		textDk: '#1e293b'
	};

	// ─── Helpers ─────────────────────────────────────────────────────────────
	function emptyMessage( selector ) {
		d3.select( selector )
			.html( '' )
			.append( 'div' )
			.attr( 'class', 'puresmtp-chart-empty' )
			.text( i18n.noData || 'No data' );
	}

	function ensureSvg( selector, width, height ) {
		var container = d3.select( selector );
		container.html( '' );
		return container
			.append( 'svg' )
			.attr( 'viewBox', '0 0 ' + width + ' ' + height )
			.attr( 'preserveAspectRatio', 'xMidYMid meet' )
			.attr( 'width', '100%' )
			.attr( 'height', height );
	}

	function tooltip() {
		var tip = d3.select( 'body' ).select( '.puresmtp-tooltip' );
		if ( tip.empty() ) {
			tip = d3.select( 'body' )
				.append( 'div' )
				.attr( 'class', 'puresmtp-tooltip' )
				.style( 'opacity', 0 );
		}
		return tip;
	}

	// ─── 1. Daily stacked bar chart ──────────────────────────────────────────
	function renderDaily() {
		var sel = '#puresmtp-chart-daily';
		var rows = ( data.daily || [] );
		if ( ! rows.length || d3.sum( rows, function ( d ) { return d.sent + d.failed; } ) === 0 ) {
			emptyMessage( sel );
			return;
		}

		var width  = 920;
		var height = 320;
		var margin = { top: 20, right: 16, bottom: 50, left: 48 };
		var iw     = width  - margin.left - margin.right;
		var ih     = height - margin.top  - margin.bottom;

		var svg = ensureSvg( sel, width, height );
		var g   = svg.append( 'g' )
			.attr( 'transform', 'translate(' + margin.left + ',' + margin.top + ')' );

		var x = d3.scaleBand()
			.domain( rows.map( function ( d ) { return d.date; } ) )
			.range( [ 0, iw ] )
			.paddingInner( 0.18 )
			.paddingOuter( 0.05 );

		var yMax = d3.max( rows, function ( d ) { return d.sent + d.failed; } ) || 1;
		var y = d3.scaleLinear()
			.domain( [ 0, yMax ] ).nice()
			.range( [ ih, 0 ] );

		// Y grid + axis.
		g.append( 'g' )
			.attr( 'class', 'puresmtp-axis puresmtp-grid' )
			.call(
				d3.axisLeft( y )
					.ticks( 5 )
					.tickSize( -iw )
					.tickFormat( d3.format( 'd' ) )
			)
			.call( function ( s ) {
				s.select( '.domain' ).remove();
				s.selectAll( 'line' ).attr( 'stroke', COLORS.grid );
				s.selectAll( 'text' ).attr( 'fill', COLORS.text );
			} );

		// X axis (rotated, every Nth label).
		var step = Math.max( 1, Math.ceil( rows.length / 14 ) );
		g.append( 'g' )
			.attr( 'class', 'puresmtp-axis' )
			.attr( 'transform', 'translate(0,' + ih + ')' )
			.call(
				d3.axisBottom( x )
					.tickValues( rows.map( function ( d, i ) { return i % step === 0 ? d.date : null; } ).filter( Boolean ) )
					.tickFormat( function ( v ) {
						var p = v.split( '-' );
						return p[ 2 ] + '.' + p[ 1 ] + '.';
					} )
			)
			.call( function ( s ) {
				s.select( '.domain' ).attr( 'stroke', COLORS.grid );
				s.selectAll( 'line' ).attr( 'stroke', COLORS.grid );
				s.selectAll( 'text' )
					.attr( 'fill', COLORS.text )
					.attr( 'transform', 'rotate(-32)' )
					.attr( 'text-anchor', 'end' )
					.attr( 'dx', '-6px' )
					.attr( 'dy', '6px' );
			} );

		var tip = tooltip();

		// Sent (bottom).
		g.selectAll( '.bar-sent' )
			.data( rows )
			.join( 'rect' )
			.attr( 'class', 'bar-sent' )
			.attr( 'x', function ( d ) { return x( d.date ); } )
			.attr( 'y', function ( d ) { return y( d.sent ); } )
			.attr( 'width', x.bandwidth() )
			.attr( 'height', function ( d ) { return ih - y( d.sent ); } )
			.attr( 'fill', COLORS.sent )
			.on( 'mousemove', function ( event, d ) {
				tip.style( 'opacity', 1 )
					.html( '<strong>' + d.date + '</strong><br>' + i18n.sent + ': ' + d.sent + '<br>' + i18n.failed + ': ' + d.failed )
					.style( 'left', ( event.pageX + 12 ) + 'px' )
					.style( 'top',  ( event.pageY + 12 ) + 'px' );
			} )
			.on( 'mouseleave', function () { tip.style( 'opacity', 0 ); } );

		// Failed (top, stacked).
		g.selectAll( '.bar-failed' )
			.data( rows )
			.join( 'rect' )
			.attr( 'class', 'bar-failed' )
			.attr( 'x', function ( d ) { return x( d.date ); } )
			.attr( 'y', function ( d ) { return y( d.sent + d.failed ); } )
			.attr( 'width', x.bandwidth() )
			.attr( 'height', function ( d ) { return y( d.sent ) - y( d.sent + d.failed ); } )
			.attr( 'fill', COLORS.failed )
			.on( 'mousemove', function ( event, d ) {
				tip.style( 'opacity', 1 )
					.html( '<strong>' + d.date + '</strong><br>' + i18n.sent + ': ' + d.sent + '<br>' + i18n.failed + ': ' + d.failed )
					.style( 'left', ( event.pageX + 12 ) + 'px' )
					.style( 'top',  ( event.pageY + 12 ) + 'px' );
			} )
			.on( 'mouseleave', function () { tip.style( 'opacity', 0 ); } );

		// Legend.
		var legend = svg.append( 'g' )
			.attr( 'transform', 'translate(' + ( margin.left ) + ',6)' );
		var items = [
			{ label: i18n.sent,   color: COLORS.sent },
			{ label: i18n.failed, color: COLORS.failed }
		];
		var lg = legend.selectAll( 'g' ).data( items ).join( 'g' )
			.attr( 'transform', function ( d, i ) { return 'translate(' + ( i * 90 ) + ',0)'; } );
		lg.append( 'rect' ).attr( 'width', 11 ).attr( 'height', 11 ).attr( 'rx', 2 ).attr( 'fill', function ( d ) { return d.color; } );
		lg.append( 'text' )
			.attr( 'x', 16 )
			.attr( 'y', 9 )
			.attr( 'fill', COLORS.textDk )
			.style( 'font-size', '11px' )
			.text( function ( d ) { return d.label; } );
	}

	// ─── 2. Hourly line chart ────────────────────────────────────────────────
	function renderHourly() {
		var sel = '#puresmtp-chart-hourly';
		var rows = data.hourly || [];
		if ( ! rows.length || d3.sum( rows, function ( d ) { return d.count; } ) === 0 ) {
			emptyMessage( sel );
			return;
		}

		var width = 460, height = 240;
		var margin = { top: 14, right: 14, bottom: 32, left: 38 };
		var iw = width - margin.left - margin.right;
		var ih = height - margin.top - margin.bottom;

		var svg = ensureSvg( sel, width, height );
		var g = svg.append( 'g' ).attr( 'transform', 'translate(' + margin.left + ',' + margin.top + ')' );

		var x = d3.scaleLinear().domain( [ 0, 23 ] ).range( [ 0, iw ] );
		var y = d3.scaleLinear().domain( [ 0, d3.max( rows, function ( d ) { return d.count; } ) || 1 ] ).nice().range( [ ih, 0 ] );

		g.append( 'g' )
			.attr( 'class', 'puresmtp-axis puresmtp-grid' )
			.call( d3.axisLeft( y ).ticks( 4 ).tickSize( -iw ).tickFormat( d3.format( 'd' ) ) )
			.call( function ( s ) {
				s.select( '.domain' ).remove();
				s.selectAll( 'line' ).attr( 'stroke', COLORS.grid );
				s.selectAll( 'text' ).attr( 'fill', COLORS.text );
			} );

		g.append( 'g' )
			.attr( 'class', 'puresmtp-axis' )
			.attr( 'transform', 'translate(0,' + ih + ')' )
			.call( d3.axisBottom( x ).ticks( 12 ).tickFormat( function ( v ) { return v + 'h'; } ) )
			.call( function ( s ) {
				s.select( '.domain' ).attr( 'stroke', COLORS.grid );
				s.selectAll( 'line' ).attr( 'stroke', COLORS.grid );
				s.selectAll( 'text' ).attr( 'fill', COLORS.text );
			} );

		var area = d3.area()
			.x( function ( d ) { return x( d.hour ); } )
			.y0( ih )
			.y1( function ( d ) { return y( d.count ); } )
			.curve( d3.curveMonotoneX );

		var line = d3.line()
			.x( function ( d ) { return x( d.hour ); } )
			.y( function ( d ) { return y( d.count ); } )
			.curve( d3.curveMonotoneX );

		g.append( 'path' )
			.datum( rows )
			.attr( 'fill', COLORS.bar )
			.attr( 'fill-opacity', 0.18 )
			.attr( 'd', area );

		g.append( 'path' )
			.datum( rows )
			.attr( 'fill', 'none' )
			.attr( 'stroke', COLORS.bar )
			.attr( 'stroke-width', 2 )
			.attr( 'd', line );

		var tip = tooltip();
		g.selectAll( 'circle' )
			.data( rows )
			.join( 'circle' )
			.attr( 'cx', function ( d ) { return x( d.hour ); } )
			.attr( 'cy', function ( d ) { return y( d.count ); } )
			.attr( 'r', 3 )
			.attr( 'fill', COLORS.bar )
			.on( 'mousemove', function ( event, d ) {
				tip.style( 'opacity', 1 )
					.html( '<strong>' + d.hour + ':00</strong><br>' + i18n.count + ': ' + d.count )
					.style( 'left', ( event.pageX + 12 ) + 'px' )
					.style( 'top',  ( event.pageY + 12 ) + 'px' );
			} )
			.on( 'mouseleave', function () { tip.style( 'opacity', 0 ); } );
	}

	// ─── 3. Horizontal bar (generic) ─────────────────────────────────────────
	function renderHBar( sel, rows, labelKey, color ) {
		if ( ! rows || ! rows.length ) {
			emptyMessage( sel );
			return;
		}

		var width  = 680;
		var rowH   = 26;
		var height = Math.max( 80, rows.length * rowH + 30 );
		var margin = { top: 8, right: 50, bottom: 8, left: 220 };
		var iw     = width - margin.left - margin.right;
		var ih     = height - margin.top - margin.bottom;

		var svg = ensureSvg( sel, width, height );
		var g   = svg.append( 'g' ).attr( 'transform', 'translate(' + margin.left + ',' + margin.top + ')' );

		var y = d3.scaleBand()
			.domain( rows.map( function ( d ) { return d[ labelKey ]; } ) )
			.range( [ 0, ih ] )
			.padding( 0.22 );

		var x = d3.scaleLinear()
			.domain( [ 0, d3.max( rows, function ( d ) { return d.count; } ) || 1 ] ).nice()
			.range( [ 0, iw ] );

		g.append( 'g' )
			.attr( 'class', 'puresmtp-axis' )
			.call( d3.axisLeft( y ).tickSize( 0 ) )
			.call( function ( s ) {
				s.select( '.domain' ).remove();
				s.selectAll( 'text' )
					.attr( 'fill', COLORS.textDk )
					.style( 'font-size', '12px' )
					.each( function () {
						var t = d3.select( this );
						var txt = t.text();
						if ( txt.length > 28 ) {
							t.text( txt.substring( 0, 27 ) + '…' );
							t.append( 'title' ).text( txt );
						}
					} );
			} );

		g.selectAll( 'rect.bar' )
			.data( rows )
			.join( 'rect' )
			.attr( 'class', 'bar' )
			.attr( 'x', 0 )
			.attr( 'y', function ( d ) { return y( d[ labelKey ] ); } )
			.attr( 'width', function ( d ) { return x( d.count ); } )
			.attr( 'height', y.bandwidth() )
			.attr( 'rx', 3 )
			.attr( 'fill', color );

		g.selectAll( 'text.value' )
			.data( rows )
			.join( 'text' )
			.attr( 'class', 'value' )
			.attr( 'x', function ( d ) { return x( d.count ) + 6; } )
			.attr( 'y', function ( d ) { return y( d[ labelKey ] ) + y.bandwidth() / 2 + 4; } )
			.attr( 'fill', COLORS.textDk )
			.style( 'font-size', '12px' )
			.style( 'font-weight', '600' )
			.text( function ( d ) { return d.count; } );
	}

	// ─── Bootstrap ───────────────────────────────────────────────────────────
	document.addEventListener( 'DOMContentLoaded', function () {
		try { renderDaily();  } catch ( e ) { console.error( 'PureSMTP daily chart:', e ); }
		try { renderHourly(); } catch ( e ) { console.error( 'PureSMTP hourly chart:', e ); }
		try { renderHBar( '#puresmtp-chart-recipients', data.top_recipients || [], 'recipient', COLORS.bar ); } catch ( e ) { console.error( e ); }
		try { renderHBar( '#puresmtp-chart-sources',    data.top_sources    || [], 'source',    COLORS.bar2 ); } catch ( e ) { console.error( e ); }
	} );

} )();
