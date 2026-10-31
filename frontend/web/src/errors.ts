// Turns any failure into a message for the user (French interface), without technical details.
import { CryptoFormatError, DecryptionError, InvalidSecretKeyError, WeakKdfParametersError } from '../../packages/crypto/src';
import { ApiError } from './api';
import { KdfDowngradeError, MissingSecretKeyError } from './auth';

export function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.code) {
      case 'invalid_credentials':
        return 'Identifiants incorrects : vérifiez l’email, le mot de passe maître et la Secret Key.';
      case 'too_many_requests': {
        const minutes = Math.max(1, Math.ceil((error.retryAfterSeconds ?? 60) / 60));
        return `Trop de tentatives. Réessayez dans ${String(minutes)} minute${minutes > 1 ? 's' : ''}.`;
      }
      case 'registration_closed':
        return 'Les inscriptions sont fermées : un compte existe déjà sur ce serveur.';
      case 'session_expired':
      case 'unauthorized':
        return 'Votre session a expiré. Reconnectez-vous.';
      case 'invalid_payload':
        return 'Certaines informations saisies sont invalides.';
      default:
        return error.status >= 500 ? 'Le serveur a rencontré une erreur. Réessayez plus tard.' : 'La requête a été refusée.';
    }
  }
  if (error instanceof InvalidSecretKeyError) {
    return 'Secret Key invalide : recopiez-la depuis votre kit de secours.';
  }
  if (error instanceof MissingSecretKeyError) {
    return 'Cet appareil ne connaît pas encore votre Secret Key : saisissez-la depuis votre kit de secours.';
  }
  if (error instanceof KdfDowngradeError || error instanceof WeakKdfParametersError) {
    return 'Alerte de sécurité : le serveur a proposé des paramètres de chiffrement plus faibles que prévu. Opération refusée.';
  }
  if (error instanceof DecryptionError) {
    return 'Déchiffrement impossible : mot de passe maître ou Secret Key incorrect, ou données altérées.';
  }
  if (error instanceof CryptoFormatError) {
    return 'Données chiffrées illisibles ou altérées.';
  }
  if (error instanceof TypeError) {
    return 'Serveur injoignable. Vérifiez votre connexion.';
  }
  return 'Une erreur inattendue est survenue.';
}
