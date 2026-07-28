(() => {
	'use strict';

	const root = document.querySelector('[data-smi-root]');

	if (!root || typeof Joomla === 'undefined') {
		return;
	}

	const options = Joomla.getOptions('com_sitemoveinspector') || {};
	const form = root.querySelector('#smi-scan-form');
	const startButton = root.querySelector('[data-smi-start]');
	const cancelButton = root.querySelector('[data-smi-cancel]');
	const progress = root.querySelector('[data-smi-progress]');
	const progressBar = root.querySelector('[data-smi-progress-bar]');
	const progressLabel = root.querySelector('[data-smi-progress-label]');
	const progressDetail = root.querySelector('[data-smi-progress-detail]');
	const errorBox = root.querySelector('[data-smi-error]');
	const results = root.querySelector('[data-smi-results]');
	const overall = root.querySelector('[data-smi-overall]');
	const summary = root.querySelector('[data-smi-summary]');
	const sections = root.querySelector('[data-smi-sections]');
	const exportJob = root.querySelector('[data-smi-export-job]');
	let jobId = '';
	let cancelled = false;

	const translated = (key) => Joomla.Text._(key, key);

	const statusLabels = {
		pass: translated('COM_SITEMOVEINSPECTOR_STATUS_PASS'),
		warning: translated('COM_SITEMOVEINSPECTOR_STATUS_WARNING'),
		critical: translated('COM_SITEMOVEINSPECTOR_STATUS_CRITICAL'),
		unknown: translated('COM_SITEMOVEINSPECTOR_STATUS_UNKNOWN'),
		not_applicable: translated('COM_SITEMOVEINSPECTOR_STATUS_NOT_APPLICABLE'),
	};

	const overallLabels = {
		high_risk: translated('COM_SITEMOVEINSPECTOR_OVERALL_HIGH_RISK'),
		review_recommended: translated('COM_SITEMOVEINSPECTOR_OVERALL_REVIEW'),
		no_blockers: translated('COM_SITEMOVEINSPECTOR_OVERALL_CLEAR'),
	};

	const request = async (url, data) => {
		data.set(options.token, '1');
		const response = await fetch(url, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
			},
		});
		const payload = await response.json();

		if (!response.ok || !payload.success) {
			throw new Error(payload.message || translated('COM_SITEMOVEINSPECTOR_JS_ERROR'));
		}

		return payload.data;
	};

	const setBusy = (busy) => {
		startButton.disabled = busy;
		cancelButton.hidden = !busy;

		form.querySelectorAll('input, select').forEach((field) => {
			if (field.type !== 'hidden') {
				field.disabled = busy;
			}
		});
	};

	const showProgress = (data = {}) => {
		progress.hidden = false;
		const percent = Number.isFinite(Number(data.percent)) ? Number(data.percent) : 1;
		progressBar.value = Math.min(100, Math.max(1, percent));
		const processed = Number(data.processed_entries || 0).toLocaleString();
		const files = Number(data.file_count || 0).toLocaleString();
		progressDetail.textContent = translated('COM_SITEMOVEINSPECTOR_JS_PROCESSED')
			.replace('%1$s', processed)
			.replace('%2$s', files);
	};

	const make = (tag, className, text) => {
		const element = document.createElement(tag);

		if (className) {
			element.className = className;
		}

		if (typeof text === 'string') {
			element.textContent = text;
		}

		return element;
	};

	const renderReport = (report) => {
		const reportSummary = report.summary || {};
		const result = reportSummary.overall || 'review_recommended';
		overall.textContent = overallLabels[result] || result;
		overall.className = `smi-overall--${result}`;
		summary.replaceChildren();

		Object.entries(reportSummary.counts || {}).forEach(([status, count]) => {
			const card = make('div', 'smi-summary-card');
			card.append(
				make('strong', '', Number(count || 0).toLocaleString()),
				make('span', '', statusLabels[status] || status),
			);
			summary.append(card);
		});

		sections.replaceChildren();
		(report.sections || []).forEach((section) => {
			const sectionElement = make('section', 'smi-section');
			sectionElement.append(make('h3', '', section.title || section.id || ''));

			(section.checks || []).forEach((check) => {
				const row = make('div', 'smi-check');
				const status = make(
					'span',
					`smi-status smi-status--${check.status || 'unknown'}`,
					statusLabels[check.status] || check.status || '',
				);
				const copy = make('div', 'smi-check-copy');
				copy.append(make('h4', '', check.label || ''));

				if (check.message) {
					copy.append(make('p', '', check.message));
				}

				if (check.recommendation) {
					copy.append(
						make(
							'p',
							'smi-recommendation',
							`${translated('COM_SITEMOVEINSPECTOR_JS_ACTION')}: ${check.recommendation}`,
						),
					);
				}

				row.append(status, copy, make('div', 'smi-value', check.value || ''));
				sectionElement.append(row);
			});

			sections.append(sectionElement);
		});

		exportJob.value = jobId;
		results.hidden = false;
		results.scrollIntoView({ behavior: 'smooth', block: 'start' });
	};

	const step = async () => {
		if (cancelled || !jobId) {
			return;
		}

		const data = new FormData();
		data.append('job_id', jobId);
		const response = await request(options.stepUrl, data);

		if (cancelled) {
			return;
		}

		if (response.status === 'completed') {
			progressLabel.textContent = translated('COM_SITEMOVEINSPECTOR_JS_COMPLETE');
			showProgress(response.progress || { percent: 100 });
			setBusy(false);
			renderReport(response.report || {});
			return;
		}

		progressLabel.textContent = translated('COM_SITEMOVEINSPECTOR_JS_SCANNING');
		showProgress(response.progress || {});
		window.setTimeout(() => {
			step().catch((error) => {
				if (!cancelled) {
					fail(error);
				}
			});
		}, 80);
	};

	const fail = (error) => {
		setBusy(false);
		progress.hidden = true;
		errorBox.textContent = error instanceof Error && error.message
			? error.message
			: translated('COM_SITEMOVEINSPECTOR_JS_ERROR');
		errorBox.hidden = false;
	};

	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		const submitted = new FormData(form);
		cancelled = false;
		jobId = '';
		errorBox.hidden = true;
		results.hidden = true;
		setBusy(true);
		progressLabel.textContent = translated('COM_SITEMOVEINSPECTOR_JS_STARTING');
		showProgress({ percent: 1 });

		try {
			const response = await request(options.startUrl, submitted);
			jobId = response.job_id || '';
			showProgress(response.progress || {});
			await step();
		} catch (error) {
			if (!cancelled) {
				fail(error);
			}
		}
	});

	cancelButton.addEventListener('click', async () => {
		cancelled = true;

		if (jobId) {
			const data = new FormData();
			data.append('job_id', jobId);

			try {
				await request(options.cancelUrl, data);
			} catch (error) {
				// Cancellation is best-effort; the job expires automatically.
			}
		}

		jobId = '';
		setBusy(false);
		progress.hidden = true;
		progressLabel.textContent = translated('COM_SITEMOVEINSPECTOR_JS_CANCELLED');
	});
})();
