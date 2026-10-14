Clés RSA **de test uniquement** (générées pour la suite PHPUnit, jamais utilisées ailleurs).

- `test-private.pem` : signe les jetons "Keycloak" des tests ; sa clé publique est servie par le JWKS mocké.
- `attacker-private.pem` : signe les jetons falsifiés (doivent être rejetés).
