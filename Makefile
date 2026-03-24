.PHONY: phpunit phpstan phpcs cs-fix rector qa

phpunit:
	composer test

phpstan:
	composer phpstan

phpcs:
	composer cs:check

cs-fix:
	composer cs:fix

rector:
	composer rector

qa:
	composer qa
