import 'package:url_launcher/url_launcher.dart';

/// Ouvre la page de paiement carte sécurisée (3-D Secure) dans le navigateur.
/// Le numéro de carte n'est jamais saisi dans l'application FlashPay.
Future<bool> openCheckout(String? url) async {
  if (url == null || url.isEmpty) return false;
  return launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank');
}
