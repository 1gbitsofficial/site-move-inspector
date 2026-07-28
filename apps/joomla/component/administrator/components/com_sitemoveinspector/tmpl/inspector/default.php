<?php
/**
 * @package     1Gbits.SiteMoveInspector
 * @subpackage  com_sitemoveinspector
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

?>
<div class="smi" data-smi-root>
	<header class="smi-hero">
		<div>
			<p class="smi-eyebrow"><?php echo Text::_('COM_SITEMOVEINSPECTOR_EYEBROW'); ?></p>
			<h1><?php echo Text::_('COM_SITEMOVEINSPECTOR_HEADING'); ?></h1>
			<p class="smi-lead"><?php echo Text::_('COM_SITEMOVEINSPECTOR_INTRO'); ?></p>
		</div>
		<div class="smi-readonly">
			<span class="icon-lock" aria-hidden="true"></span>
			<div>
				<strong><?php echo Text::_('COM_SITEMOVEINSPECTOR_READ_ONLY'); ?></strong>
				<span><?php echo Text::_('COM_SITEMOVEINSPECTOR_LOCAL_ONLY'); ?></span>
			</div>
		</div>
	</header>

	<section class="smi-panel" aria-labelledby="smi-destination-title">
		<div class="smi-panel-heading">
			<div>
				<h2 id="smi-destination-title"><?php echo Text::_('COM_SITEMOVEINSPECTOR_DESTINATION_TITLE'); ?></h2>
				<p><?php echo Text::_('COM_SITEMOVEINSPECTOR_DESTINATION_HELP'); ?></p>
			</div>
			<span class="smi-optional"><?php echo Text::_('COM_SITEMOVEINSPECTOR_OPTIONAL'); ?></span>
		</div>

		<form id="smi-scan-form">
			<div class="smi-fields">
				<div class="control-group">
					<label class="control-label" for="smi-php-version">
						<?php echo Text::_('COM_SITEMOVEINSPECTOR_DESTINATION_PHP'); ?>
					</label>
					<div class="controls">
						<input
							id="smi-php-version"
							name="destination[php_version]"
							type="text"
							inputmode="decimal"
							placeholder="8.3"
							pattern="[0-9]+(?:\.[0-9]+){0,3}"
						>
					</div>
				</div>

				<div class="control-group">
					<label class="control-label" for="smi-db-engine">
						<?php echo Text::_('COM_SITEMOVEINSPECTOR_DESTINATION_DB_ENGINE'); ?>
					</label>
					<div class="controls">
						<select id="smi-db-engine" name="destination[database_engine]">
							<option value=""><?php echo Text::_('COM_SITEMOVEINSPECTOR_NOT_PROVIDED'); ?></option>
							<option value="mysql">MySQL</option>
							<option value="mariadb">MariaDB</option>
							<option value="postgresql">PostgreSQL</option>
						</select>
					</div>
				</div>

				<div class="control-group">
					<label class="control-label" for="smi-db-version">
						<?php echo Text::_('COM_SITEMOVEINSPECTOR_DESTINATION_DB_VERSION'); ?>
					</label>
					<div class="controls">
						<input
							id="smi-db-version"
							name="destination[database_version]"
							type="text"
							inputmode="decimal"
							placeholder="10.6"
							pattern="[0-9]+(?:\.[0-9]+){0,3}"
						>
					</div>
				</div>

				<div class="control-group">
					<label class="control-label" for="smi-disk-space">
						<?php echo Text::_('COM_SITEMOVEINSPECTOR_DESTINATION_DISK_GB'); ?>
					</label>
					<div class="controls">
						<input
							id="smi-disk-space"
							name="destination[disk_space_gb]"
							type="number"
							min="0"
							max="1000000"
							step="0.1"
							placeholder="20"
						>
					</div>
				</div>
			</div>

			<div class="smi-actions">
				<button class="btn btn-primary btn-lg" type="submit" data-smi-start>
					<span class="icon-search" aria-hidden="true"></span>
					<?php echo Text::_('COM_SITEMOVEINSPECTOR_START_SCAN'); ?>
				</button>
				<button class="btn btn-outline-secondary" type="button" data-smi-cancel hidden>
					<?php echo Text::_('JCANCEL'); ?>
				</button>
				<p><?php echo Text::_('COM_SITEMOVEINSPECTOR_SCAN_NOTE'); ?></p>
			</div>
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
	</section>

	<section class="smi-progress" data-smi-progress hidden aria-live="polite">
		<div class="smi-progress-copy">
			<strong data-smi-progress-label><?php echo Text::_('COM_SITEMOVEINSPECTOR_JS_STARTING'); ?></strong>
			<span data-smi-progress-detail></span>
		</div>
		<progress max="100" value="1" data-smi-progress-bar>1%</progress>
	</section>

	<div class="alert alert-danger" data-smi-error hidden role="alert"></div>

	<section class="smi-results" data-smi-results hidden aria-labelledby="smi-results-title">
		<div class="smi-results-heading">
			<div>
				<p class="smi-eyebrow"><?php echo Text::_('COM_SITEMOVEINSPECTOR_REPORT'); ?></p>
				<h2 id="smi-results-title" data-smi-overall></h2>
			</div>
			<div class="smi-export">
				<form
					method="post"
					action="<?php echo Route::_('index.php?option=com_sitemoveinspector&task=export.download'); ?>"
					data-smi-export-form
				>
					<input type="hidden" name="job_id" value="" data-smi-export-job>
					<button class="btn btn-outline-primary" type="submit" name="report_format" value="txt">
						<?php echo Text::_('COM_SITEMOVEINSPECTOR_EXPORT_TXT'); ?>
					</button>
					<button class="btn btn-outline-primary" type="submit" name="report_format" value="json">
						<?php echo Text::_('COM_SITEMOVEINSPECTOR_EXPORT_JSON'); ?>
					</button>
					<?php echo HTMLHelper::_('form.token'); ?>
				</form>
			</div>
		</div>

		<div class="smi-summary" data-smi-summary></div>
		<div class="smi-sections" data-smi-sections></div>

		<p class="smi-disclaimer"><?php echo Text::_('COM_SITEMOVEINSPECTOR_DISCLAIMER'); ?></p>
	</section>

	<footer class="smi-footer">
		<span><?php echo Text::_('COM_SITEMOVEINSPECTOR_PRIVACY_FOOTER'); ?></span>
		<a href="https://1gbits.com/" target="_blank" rel="noopener noreferrer">1Gbits</a>
	</footer>
</div>
