/// URL de base de l'API FlashPay.
/// Par défaut : le backend en ligne sur Railway (HTTPS).
/// Pour tester contre un serveur local :
///   flutter run --dart-define=FLASHPAY_API_URL=http://10.0.2.2:8000/api   (émulateur Android)
///   flutter run --dart-define=FLASHPAY_API_URL=http://localhost:8000/api  (Chrome / iOS)
const String kApiBaseUrl = String.fromEnvironment(
  'FLASHPAY_API_URL',
  defaultValue: 'https://flashpay-production-b5de.up.railway.app/api',
);

const String kAppName = 'FlashPay';
const String kCurrency = 'XAF';
