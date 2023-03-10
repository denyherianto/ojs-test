<?php

/**
 * @file classes/i18n/LocaleFile.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LocaleFile
 * @ingroup i18n
 *
 * @brief Abstraction of a locale file
 */


class LocaleFile {
	/** @var object Cache of this locale file */
	var $cache;

	/** @var string The identifier for this locale file */
	var $locale;

	/** @var string The filename for this locale file */
	var $filename;

	/**
	 * Constructor.
	 * @param $locale string Key for this locale file
	 * @param $filename string Filename to this locale file
	 */
	function __construct($locale, $filename) {
		$this->locale = $locale;
		$this->filename = $filename;
	}

	/**
	 * Get the cache object for this locale file.
	 */
	function _getCache($locale) {
		if (!isset($this->cache)) {
			$cacheManager = CacheManager::getManager();
			$this->cache = $cacheManager->getFileCache(
				'locale', md5($this->filename),
				array($this, '_cacheMiss')
			);

			// Check to see if the cache is outdated.
			// Only some kinds of caches track cache dates;
			// if there's no date available (ie cachedate is
			// null), we have to assume it's up to date.
			$cacheTime = $this->cache->getCacheTime();
			if ($cacheTime === null || $cacheTime < filemtime($this->filename)) {
				// This cache is out of date; flush it.
				$this->cache->setEntireCache(LocaleFile::load($this->filename));
			}
		}
		return $this->cache;
	}

	/**
	 * Register a cache miss.
	 */
	function _cacheMiss($cache, $id) {
		return null; // It's not in this locale file.
	}

	/**
	 * Get the filename for this locale file.
	 */
	function getFilename() {
		return $this->filename;
	}

	/**
	 * Translate a string using the selected locale.
	 * Substitution works by replacing tokens like "{$foo}" with the value of
	 * the parameter named "foo" (if supplied).
	 * @param $key string
	 * @param $params array named substitution parameters
	 * @param $locale string the locale to use
	 * @return string
	 */
	function translate($key, $params = array(), $locale = null) {
		if ($this->isValid()) {
			$key = trim($key);
			if (empty($key)) {
				return '';
			}

			$cache = $this->_getCache($this->locale);
			$message = $cache->get($key);
			if (!isset($message)) {
				// Try to force loading the plugin locales.
				$message = $this->_cacheMiss($cache, $key);
			}

			if (isset($message)) {
				if (!empty($params)) {
					// Substitute custom parameters
					foreach ($params as $key => $value) {
						$message = str_replace("{\$$key}", $value ?? '', $message);
					}
				}

				// if client encoding is set to iso-8859-1, transcode string from utf8 since we store all XML files in utf8
				if (LOCALE_ENCODING == "iso-8859-1") $message = utf8_decode($message);

				return $message;
			}
		}
		return null;
	}

	/**
	 * Static method: Load a locale array from a file. Not cached!
	 * @param $filename string Filename to locale .po file to load
	 * @param array
	 */
	static function &load($filename) {
		$localeData = [];
		foreach (Gettext\Translations::fromPoFile($filename) as $translation) {
			$translatedText = $translation->getTranslation();
			if ($translatedText !== '') $localeData[$translation->getOriginal()] = $translatedText;
		}
		return $localeData;
	}

	/**
	 * Check if a locale is valid.
	 * @param $locale string
	 * @return boolean
	 */
	function isValid() {
		return isset($this->locale) && file_exists($this->filename);
	}

	/**
	 * Test a locale file against the given reference locale file and
	 * return an array of errorType => array(errors).
	 * @param $referenceLocaleFile object
	 * @return array
	 */
	function testLocale(&$referenceLocaleFile) {
		$errors = array(
			LOCALE_ERROR_MISSING_KEY => array(),
			LOCALE_ERROR_EXTRA_KEY => array(),
			LOCALE_ERROR_DIFFERING_PARAMS => array(),
			LOCALE_ERROR_MISSING_FILE => array()
		);

		if ($referenceLocaleFile->isValid()) {
			if (!$this->isValid()) {
				$errors[LOCALE_ERROR_MISSING_FILE][] = array(
					'locale' => $this->locale,
					'filename' => $this->filename
				);
				return $errors;
			}
		} else {
			// If the reference file itself does not exist or is invalid then
			// there's nothing to be translated here.
			return $errors;
		}

		$localeContents = LocaleFile::load($this->filename);
		$referenceContents = LocaleFile::load($referenceLocaleFile->filename);

		foreach ($referenceContents as $key => $referenceValue) {
			if (!isset($localeContents[$key])) {
				$errors[LOCALE_ERROR_MISSING_KEY][] = array(
					'key' => $key,
					'locale' => $this->locale,
					'filename' => $this->filename,
					'reference' => $referenceValue
				);
				continue;
			}
			$value = $localeContents[$key];

			$referenceParams = AppLocale::getParameterNames($referenceValue);
			$params = AppLocale::getParameterNames($value);
			if (count(array_diff($referenceParams, $params)) > 0) {
				$errors[LOCALE_ERROR_DIFFERING_PARAMS][] = array(
					'key' => $key,
					'locale' => $this->locale,
					'mismatch' => array_diff($referenceParams, $params),
					'filename' => $this->filename,
					'reference' => $referenceValue,
					'value' => $value
				);
			}
			// After processing a key, remove it from the list;
			// this way, the remainder at the end of the loop
			// will be extra unnecessary keys.
			unset($localeContents[$key]);
		}

		// Leftover keys are extraneous.
		foreach ($localeContents as $key => $value) {
			$errors[LOCALE_ERROR_EXTRA_KEY][] = array(
				'key' => $key,
				'locale' => $this->locale,
				'filename' => $this->filename
			);
		}

		return $errors;
	}
}


