<?php
/**
 * Class QRMarkup
 *
 * @created      17.12.2016
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2016 Smiley
 * @license      MIT
 */

namespace chillerlan\QRCode\Output;

use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\QRCode;

use function implode, is_string, ksort, sprintf, strip_tags, trim;

/**
 * Converts the matrix into markup types: HTML, SVG, ...
 */
class QRMarkup extends QROutputAbstract{

	protected string $defaultMode = QRCode::OUTPUT_MARKUP_SVG;

	/**
	 * @inheritDoc
	 */
	protected function setModuleValues():void{

		foreach($this::DEFAULT_MODULE_VALUES as $M_TYPE => $defaultValue){
			$v = $this->options->moduleValues[$M_TYPE] ?? null;

			if(!is_string($v)){
				$this->moduleValues[$M_TYPE] = $defaultValue
					? $this->options->markupDark
					: $this->options->markupLight;
			}
			else{
				$this->moduleValues[$M_TYPE] = trim(strip_tags($v), " '\"\r\n\t");
			}

		}

	}

	/**
	 * HTML output
	 */
	protected function html(string $file = null):string{

		$html = empty($this->options->cssClass)
			? '<div>'
			: '<div class="'.$this->options->cssClass.'">';

		$html .= $this->options->eol;

		foreach($this->matrix->matrix() as $row){
			$html .= '<div>';

			foreach($row as $M_TYPE){
				$html .= '<span style="background: '.$this->moduleValues[$M_TYPE].';"></span>';
			}

			$html .= '</div>'.$this->options->eol;
		}

		$html .= '</div>'.$this->options->eol;

		if($file !== null){
			return '<!DOCTYPE html>'.
			       '<head><meta charset="UTF-8"><title>QR Code</title></head>'.
			       '<body>'.$this->options->eol.$html.'</body>';
		}

		return $html;
	}

	/**
	 * SVG output
	 *
	 * @see https://github.com/codemasher/php-qrcode/pull/5
	 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/svg
	 * @see https://www.sarasoueidan.com/demos/interactive-svg-coordinate-system/
	 */
	protected function svg(string $file = null):string{
		$svg = $this->svgHeader();

		if(!empty($this->options->svgDefs)){
			$svg .= sprintf('<defs>%1$s%2$s</defs>%2$s', $this->options->svgDefs, $this->options->eol);
		}

		$svg .= $this->svgPaths();

		// close svg
		$svg .= sprintf('%1$s</svg>%1$s', $this->options->eol);

		// transform to data URI only when not saving to file
		if($file === null && $this->options->imageBase64){
			$svg = $this->base64encode($svg, 'image/svg+xml');
		}

		return $svg;
	}

	/**
	 * returns the <svg> header with the given options parsed
	 */
	protected function svgHeader():string{
		$width  = $this->options->svgWidth !== null ? sprintf(' width="%s"', $this->options->svgWidth) : '';
		$height = $this->options->svgHeight !== null ? sprintf(' height="%s"', $this->options->svgHeight) : '';

		/** @noinspection HtmlUnknownAttribute */
		return sprintf(
			'<?xml version="1.0" encoding="UTF-8"?>%6$s'.
			'<svg xmlns="http://www.w3.org/2000/svg" class="qr-svg %1$s" viewBox="0 0 %2$s %2$s" preserveAspectRatio="%3$s"%4$s%5$s>%6$s',
			$this->options->cssClass,
			$this->options->svgViewBoxSize ?? $this->moduleCount,
			$this->options->svgPreserveAspectRatio,
			$width,
			$height,
			$this->options->eol
		);
	}

	/**
	 * returns one or more SVG <path> elements
	 *
	 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/path
	 */
	protected function svgPaths():string{
		$paths = [];

		// collect the modules for each type
		foreach($this->matrix->matrix() as $y => $row){
			foreach($row as $x => $M_TYPE){

				if($this->options->svgConnectPaths && !$this->matrix->checkTypes($x, $y, $this->options->svgExcludeFromConnect)){
					// to connect paths we'll redeclare the $M_TYPE to data only
					$M_TYPE = QRMatrix::M_DATA;

					if($this->matrix->check($x, $y)){
						$M_TYPE |= QRMatrix::IS_DARK;
					}
				}

				// collect the modules per $M_TYPE
				$paths[$M_TYPE][] = $this->svgModule($x, $y);
			}
		}

		// beautify output
		ksort($paths);

		$svg = [];

		// create the path elements
		foreach($paths as $M_TYPE => $path){
			$path = trim(implode(' ', $path));

			if(empty($path)){
				continue;
			}

			$cssClass = implode(' ', [
				'qr-'.$M_TYPE,
				($M_TYPE & QRMatrix::IS_DARK) === QRMatrix::IS_DARK ? 'dark' : 'light',
				$this->options->cssClass,
			]);

			$format = empty($this->moduleValues[$M_TYPE])
				? '<path class="%1$s" d="%2$s"/>'
				: '<path class="%1$s" fill="%3$s" fill-opacity="%4$s" d="%2$s"/>';

			$svg[] = sprintf($format, $cssClass, $path, $this->moduleValues[$M_TYPE], $this->options->svgOpacity);
		}

		return implode($this->options->eol, $svg);
	}

	/**
	 * returns a path segment for a single module
	 *
	 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Attribute/d
	 */
	protected function svgModule(int $x, int $y):string{

		if($this->options->imageTransparent && !$this->matrix->check($x, $y)){
			return '';
		}

		if($this->options->svgDrawCircularModules && !$this->matrix->checkTypes($x, $y, $this->options->svgKeepAsSquare)){
			$r = $this->options->svgCircleRadius;

			return sprintf(
				'M%1$s %2$s a%3$s %3$s 0 1 0 %4$s 0 a%3$s,%3$s 0 1 0 -%4$s 0Z',
				($x + 0.5 - $r),
				($y + 0.5),
				$r,
				($r * 2)
			);

		}

		return sprintf('M%1$s %2$s h%3$s v1 h-%4$sZ', $x, $y, 1, 1);
	}

}
