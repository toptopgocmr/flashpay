import 'package:flutter_test/flutter_test.dart';
import 'package:flashpay_app/widgets/fp_logo.dart';
import 'package:flutter/material.dart';

void main() {
  testWidgets('Le logo FlashPay se construit', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: FpLogo(size: 40))));
    expect(find.byType(FpLogo), findsOneWidget);
  });
}
