/// Pays / opérateurs couverts par FlashPay (fournis par GET /api/pay/corridors).
class FpOperator {
  final String corridor;
  final String label;
  final String rail;
  final List<String> prefixes;

  const FpOperator({required this.corridor, required this.label, required this.rail, required this.prefixes});

  factory FpOperator.fromJson(Map<String, dynamic> j) => FpOperator(
        corridor: j['corridor'] as String,
        label: j['label'] as String,
        rail: (j['rail'] ?? 'peex') as String,
        prefixes: (j['prefixes'] as List).map((e) => e.toString()).toList(),
      );
}

class FpCountry {
  final String iso;
  final String name;
  final String flag;
  final String zone;
  final String dial; // "+242"
  final int localLength;
  final String currency;
  final bool collect;
  final bool payout;
  final List<FpOperator> operators;

  const FpCountry({
    required this.iso,
    required this.name,
    required this.flag,
    required this.zone,
    required this.dial,
    required this.localLength,
    required this.currency,
    required this.collect,
    required this.payout,
    required this.operators,
  });

  factory FpCountry.fromJson(Map<String, dynamic> j) => FpCountry(
        iso: j['country'] as String,
        name: j['name'] as String,
        flag: (j['flag'] ?? '') as String,
        zone: (j['zone'] ?? '') as String,
        dial: j['dial'] as String,
        localLength: (j['local_length'] as num).toInt(),
        currency: j['currency'] as String,
        collect: j['collect'] == true,
        payout: j['payout'] == true,
        operators: (j['operators'] as List).map((e) => FpOperator.fromJson(e as Map<String, dynamic>)).toList(),
      );

  String get dialDigits => dial.replaceAll('+', '');

  /// Pays où le 0 initial fait partie du numéro international.
  bool get keepsLeadingZero => const ['CG', 'GA', 'CI', 'BJ'].contains(iso);

  /// Normalise un numéro saisi au format national de ce pays (sans indicatif).
  String normalizeLocal(String input) {
    var digits = input.replaceAll(RegExp(r'\D'), '');
    if (digits.startsWith(dialDigits) && digits.length > localLength) {
      digits = digits.substring(dialDigits.length);
    }
    if (keepsLeadingZero) {
      if (iso == 'BJ' && digits.length == 8) digits = '01$digits';
      if (digits.length == localLength - 1) digits = '0$digits';
    } else if (digits.length == localLength + 1 && digits.startsWith('0')) {
      digits = digits.substring(1);
    }
    return digits;
  }

  bool isValidLocal(String local) => local.length == localLength;

  FpOperator? operatorFor(String local) {
    FpOperator? best;
    var bestLen = 0;
    for (final op in operators) {
      for (final p in op.prefixes) {
        if (local.startsWith(p) && p.length > bestLen) {
          best = op;
          bestLen = p.length;
        }
      }
    }
    return best;
  }

  String international(String local) => '$dial$local';
}
