(function() {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;

	document.addEventListener('DOMContentLoaded', function() {
		initSearchFilters();
		initSortableColumns();
		initShowInactiveCheckbox();
		initStatusFilter();
		initClientFilter();
		initMappingTypeFilter();
	});

	/**
	 * Initialize search filters for all tables
	 */
	function initSearchFilters() {
		const searchInputs = document.querySelectorAll('.soli-search-input');

		searchInputs.forEach(function(input) {
			const tableId = input.dataset.table;
			const table = document.getElementById(tableId);
			const recordCount = document.querySelector('.soli-record-count');

			if (!table) {
				return;
			}

			input.addEventListener('input', function() {
				filterTable(table, input.value, recordCount);
			});
		});
	}

	/**
	 * Calculate fuzzy match score between query and text
	 * Returns 0 for no match, higher scores for better matches
	 */
	function fuzzyScore(query, text) {
		if (!query) return 1;
		if (!text) return 0;

		query = query.toLowerCase();
		text = text.toLowerCase();

		// Exact match gets highest score
		if (text.includes(query)) {
			return 100 + (query.length * 10);
		}

		let score = 0;
		let queryIndex = 0;
		let lastMatchIndex = -1;
		let consecutiveBonus = 0;

		for (let i = 0; i < text.length && queryIndex < query.length; i++) {
			if (text[i] === query[queryIndex]) {
				// Base score for character match
				score += 10;

				// Bonus for consecutive matches
				if (lastMatchIndex === i - 1) {
					consecutiveBonus += 5;
					score += consecutiveBonus;
				} else {
					consecutiveBonus = 0;
				}

				// Bonus for word start matches
				if (i === 0 || text[i - 1] === ' ' || text[i - 1] === '-' || text[i - 1] === '_') {
					score += 15;
				}

				lastMatchIndex = i;
				queryIndex++;
			}
		}

		// All query characters must be found in order
		if (queryIndex < query.length) {
			return 0;
		}

		return score;
	}

	/**
	 * Filter table rows based on search query with fuzzy matching
	 */
	function filterTable(table, query, recordCountElement) {
		const tbody = table.querySelector('tbody');
		const rows = Array.from(tbody.querySelectorAll('tr:not(.no-results-row)'));
		const searchTerms = query.trim();
		let visibleCount = 0;
		const totalCount = parseInt(recordCountElement?.dataset.total || rows.length);

		// Calculate scores and filter
		const scoredRows = rows.map(function(row) {
			const cells = row.querySelectorAll('td');
			let rowText = '';
			cells.forEach(function(cell) {
				rowText += ' ' + cell.textContent;
			});

			return {
				row: row,
				score: fuzzyScore(searchTerms, rowText)
			};
		});

		// Sort by score (highest first) when searching
		if (searchTerms) {
			scoredRows.sort(function(a, b) {
				return b.score - a.score;
			});
		}

		// Show/hide rows and reorder
		scoredRows.forEach(function(item) {
			if (item.score > 0) {
				item.row.classList.remove('hidden');
				tbody.appendChild(item.row);
				visibleCount++;
			} else {
				item.row.classList.add('hidden');
			}
		});

		// Update record count
		if (recordCountElement) {
			if (searchTerms === '') {
				recordCountElement.textContent = sprintf(
					/* translators: %d: number of records */
					_n( '%d record', '%d records', totalCount, 'soli-passport' ),
					totalCount
				);
			} else {
				recordCountElement.textContent = sprintf(
					/* translators: 1: visible count, 2: total count */
					__( '%1$d of %2$d records', 'soli-passport' ),
					visibleCount,
					totalCount
				);
			}
		}

		// Show/hide no results message
		updateNoResultsMessage(tbody, visibleCount);
	}

	/**
	 * Show or hide "no results" message
	 */
	function updateNoResultsMessage(tbody, visibleCount) {
		let noResultsRow = tbody.querySelector('.no-results-row');

		if (visibleCount === 0) {
			if (!noResultsRow) {
				noResultsRow = document.createElement('tr');
				noResultsRow.className = 'no-results-row';
				const td = document.createElement('td');
				td.className = 'no-results';
				td.setAttribute('colspan', '100');
				td.textContent = __( 'No results found', 'soli-passport' );
				noResultsRow.appendChild(td);
				tbody.appendChild(noResultsRow);
			}
			noResultsRow.classList.remove('hidden');
		} else if (noResultsRow) {
			noResultsRow.classList.add('hidden');
		}
	}

	/**
	 * Initialize sortable columns
	 */
	function initSortableColumns() {
		const sortableHeaders = document.querySelectorAll('.soli-table th.sortable');

		sortableHeaders.forEach(function(header) {
			header.addEventListener('click', function() {
				const table = header.closest('table');
				const columnIndex = Array.from(header.parentNode.children).indexOf(header);
				const sortKey = header.dataset.sort;
				const currentDirection = header.classList.contains('sort-asc') ? 'asc' :
				                         header.classList.contains('sort-desc') ? 'desc' : 'none';

				// Remove sort classes from all headers
				table.querySelectorAll('th.sortable').forEach(function(th) {
					th.classList.remove('sort-asc', 'sort-desc');
					th.removeAttribute('aria-sort');
				});

				// Determine new sort direction
				let newDirection;
				if (currentDirection === 'none' || currentDirection === 'desc') {
					newDirection = 'asc';
				} else {
					newDirection = 'desc';
				}

				// Apply new sort class
				header.classList.add('sort-' + newDirection);
				header.setAttribute('aria-sort', newDirection === 'asc' ? 'ascending' : 'descending');

				// Sort the table
				sortTable(table, columnIndex, newDirection);
			});
		});
	}

	/**
	 * Sort table by column
	 */
	function sortTable(table, columnIndex, direction) {
		const tbody = table.querySelector('tbody');
		const rows = Array.from(tbody.querySelectorAll('tr:not(.no-results-row)'));

		rows.sort(function(a, b) {
			const aValue = a.children[columnIndex]?.textContent.trim().toLowerCase() || '';
			const bValue = b.children[columnIndex]?.textContent.trim().toLowerCase() || '';

			if (aValue < bValue) {
				return direction === 'asc' ? -1 : 1;
			}
			if (aValue > bValue) {
				return direction === 'asc' ? 1 : -1;
			}
			return 0;
		});

		// Re-append sorted rows
		rows.forEach(function(row) {
			tbody.appendChild(row);
		});
	}

	/**
	 * Initialize show inactive checkbox
	 */
	function initShowInactiveCheckbox() {
		const checkbox = document.querySelector('.soli-show-inactive');
		if (!checkbox) {
			return;
		}

		checkbox.addEventListener('change', function() {
			const baseUrl = this.dataset.url;
			const url = new URL(baseUrl, window.location.origin);

			if (this.checked) {
				url.searchParams.set('show_inactive', '1');
			} else {
				url.searchParams.delete('show_inactive');
			}

			window.location.href = url.toString();
		});
	}

	/**
	 * Initialize status filter
	 */
	function initStatusFilter() {
		const select = document.querySelector('.soli-status-filter');
		if (!select) {
			return;
		}

		select.addEventListener('change', function() {
			const baseUrl = this.dataset.url;
			const url = new URL(baseUrl, window.location.origin);

			if (this.value) {
				url.searchParams.set('status', this.value);
			} else {
				url.searchParams.delete('status');
			}

			window.location.href = url.toString();
		});
	}

	/**
	 * Initialize client filter (for OIDC User Roles page)
	 */
	function initClientFilter() {
		const select = document.querySelector('.soli-filter-client');
		if (!select) {
			return;
		}

		select.addEventListener('change', function() {
			const baseUrl = this.dataset.url;
			const url = new URL(baseUrl, window.location.origin);

			if (this.value) {
				url.searchParams.set('client', this.value);
			} else {
				url.searchParams.delete('client');
			}

			window.location.href = url.toString();
		});
	}

	/**
	 * Initialize mapping type filter (for Role Mappings page)
	 */
	function initMappingTypeFilter() {
		const select = document.querySelector('.soli-filter-mapping-type');
		if (!select) {
			return;
		}

		select.addEventListener('change', function() {
			const baseUrl = this.dataset.url;
			const url = new URL(baseUrl, window.location.origin);

			if (this.value) {
				url.searchParams.set('type', this.value);
			} else {
				url.searchParams.delete('type');
			}

			window.location.href = url.toString();
		});
	}
})();
