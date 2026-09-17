import { useEffect } from 'react'
import AppFooter from '../components/common/AppFooter'
import HeaderAuthActions from '../components/common/HeaderAuthActions'
import PrimaryNav from '../components/common/PrimaryNav'
import ygtLogo from '../assets/yt-logo.png'
import { scrollPageToTop } from '../utils/scrollPageToTop'
import './HomePage.css'
import './LegalDocumentPage.css'

const legalDocuments = {
  terms: {
    title: 'Terms & Conditions',
    effectiveDate: '16 September 2026',
    intro: [
      'Welcome to Your Good Time. By registering for an account, accessing the website, making a payment, or using any of our services, you agree to these Terms & Conditions.',
    ],
    sections: [
      {
        heading: '1. SERVICES',
        paragraphs: [
          'Your Good Time provides astrology-related information and services based on the birth details and other information provided by the user.',
          'Our services currently include:',
        ],
        items: [
          'Past Prediction – available free of charge to eligible registered users.',
          'Future Prediction – a paid service providing astrology-based future prediction videos.',
          'Talk to Astrologer – a paid service through which the user can speak with an astrologer at a mutually confirmed available time.',
          'Temple Remedy for Your Horoscope – a paid service through which a temple/remedy recommendation is provided based on the user\'s horoscope.',
        ],
        closing: [
          'Services, pricing, availability, and features may be updated from time to time.',
        ],
      },
      {
        heading: '2. REGISTRATION AND USER INFORMATION',
        paragraphs: [
          'Users must provide accurate information while registering, including their name, date of birth, time of birth, place of birth, email address, and other information requested by the service.',
          'The accuracy and relevance of horoscope calculations and astrology-based content depend on the information supplied by the user. Your Good Time is not responsible for results affected by incorrect, incomplete, or inaccurate information supplied by the user.',
          'Users must be 16 years of age or older to create an account and use the registered-user services.',
        ],
      },
      {
        heading: '3. PAST PREDICTION',
        paragraphs: [
          'Past Prediction is currently provided free of charge.',
          'Past Prediction may be delivered through prerecorded astrology prediction videos selected according to the user\'s horoscope and prediction data.',
          'Availability of the free service may be subject to registration and the technical availability of the platform.',
        ],
      },
      {
        heading: '4. FUTURE PREDICTION',
        paragraphs: [
          'Future Prediction is a paid service.',
          'The prediction is primarily delivered through prerecorded videos selected according to the user\'s horoscope and relevant prediction calculations.',
          'The original prediction videos are recorded in Tamil. Where other supported languages are selected, the video/audio may be translated or dubbed using Artificial Intelligence (AI) technology.',
          'AI-generated translation or dubbing may occasionally contain differences in pronunciation, expression, timing, or translation compared with the original Tamil recording. The underlying astrology content is based on the corresponding original prediction material.',
        ],
      },
      {
        heading: '5. TALK TO ASTROLOGER',
        paragraphs: [
          'Talk to Astrologer is a paid service and is subject to the astrologer\'s availability.',
          'After payment has been received and verified, the appointment schedule will be coordinated with the customer. The confirmed date and time will depend on available appointment slots and mutual confirmation.',
          'The consultation will normally be conducted through a voice call using the contact details or communication method confirmed with the customer.',
          'Users are requested to be available at the confirmed appointment time.',
        ],
      },
      {
        heading: '6. TEMPLE REMEDY FOR YOUR HOROSCOPE',
        paragraphs: [
          'Temple Remedy for Your Horoscope is a paid astrology service.',
          'Based on the user\'s horoscope, astrology assessment, and relevant circumstances, Your Good Time may recommend a temple, deity, prayer, worship method, or related spiritual/remedial guidance.',
          'Such recommendations are provided as astrology and spiritual guidance. The user is responsible for deciding whether and how to follow the recommendation and for any associated travel, offerings, expenses, or other arrangements unless a particular service explicitly states otherwise.',
        ],
      },
      {
        heading: '7. PAYMENTS AND VERIFICATION',
        paragraphs: [
          'Paid services currently accept payment through the GPay/payment method displayed on the Your Good Time website.',
          'After making payment, the registered user may be required to submit the requested payment reference or proof of payment.',
          'Submitting payment information does not by itself mean that the service has been activated.',
          'The Your Good Time administration team will verify the payment. The requested paid service will be initiated or activated only after successful payment verification.',
          'If a payment cannot be identified or verified, the customer may be contacted for additional payment details.',
        ],
      },
      {
        heading: '8. PRICING',
        paragraphs: [
          'The price applicable to a paid service will be the amount displayed or communicated for that service at the time the customer places the request.',
          'Users should review the service details and applicable charges before making payment.',
        ],
      },
      {
        heading: '9. ASTROLOGY DISCLAIMER',
        paragraphs: [
          'Astrology predictions, horoscope interpretations, remedies, consultations, and related content provided through Your Good Time are intended for personal guidance, spiritual purposes, and general informational purposes.',
          'Astrology is interpretive in nature, and outcomes cannot be guaranteed.',
          'Users should exercise their own judgment when making personal, financial, medical, legal, career, relationship, or other important decisions. Your Good Time\'s astrology services should not be treated as a substitute for qualified professional advice where such advice is appropriate.',
        ],
      },
      {
        heading: '10. VIDEO AVAILABILITY AND TECHNICAL ISSUES',
        paragraphs: [
          'Your Good Time makes reasonable efforts to keep purchased prediction videos accessible to eligible users.',
          'If a user experiences a genuine technical problem that prevents an entitled video from playing or being accessed, the user should report the issue through the available support channel.',
          'We will make reasonable efforts to resolve video-access or playback issues within 24 hours after the issue is reported.',
          'Resolution may include correcting access, restoring availability, fixing a technical issue, or providing another appropriate means of accessing the purchased content.',
        ],
      },
      {
        heading: '11. INTELLECTUAL PROPERTY',
        paragraphs: [
          'Videos, astrology content, text, graphics, branding, designs, recordings, translations, and other materials made available through Your Good Time are intended for the personal use of the registered user.',
          'Unless expressly permitted, users must not copy, record, reproduce, redistribute, resell, publish, commercially exploit, or provide unauthorized access to paid or protected content.',
        ],
      },
      {
        heading: '12. ACCOUNT AND SERVICE MISUSE',
        paragraphs: [
          'Users must not attempt to bypass access controls, share protected video access with unauthorized persons, interfere with the website or its security, misuse another user\'s account, or use the service for unlawful purposes.',
          'Your Good Time may restrict access where misuse, fraud, unauthorized sharing, or a violation of these Terms is reasonably identified.',
        ],
      },
      {
        heading: '13. CHANGES TO SERVICES',
        paragraphs: [
          'Your Good Time may update the website, service features, supported languages, prices, payment methods, appointment processes, and availability of services when required.',
          'Material changes to these Terms may be reflected by updating the Terms & Conditions displayed on the website.',
        ],
      },
      {
        heading: '14. ACCEPTANCE',
        paragraphs: [
          'By creating an account, using a service, or making payment for a paid service, you confirm that you have read and accepted these Terms & Conditions together with the applicable Privacy Policy, Disclaimer, and Refund & Cancellation Policy.',
        ],
      },
    ],
  },
  privacy: {
    title: 'Privacy Policy',
    intro: [
      'Our privacy policy is centered at one central goal and that is to keep the information with us which we ask in our site www.yourgoodtime.in during registration. This statement is designed to highlight our strong commitment to your privacy. The following discloses our information collection and dissemination practices for our website as above:',
      "Our registration form requires users to give specific information (i.e.: name, date of birth, time and place of birth, e-mail address) and other demographic information (i.e.: preferred deity). We take this specific information to uniquely identify you as a unique user / member of this site as well as to desist minor's, who, by law, are prohibited from using this site.",
      "We also use the contact information from the registration form to send the user promotional material based on the options that the specific choice that the user has opted for. The user's contact information will also be used to contact the user when necessary mostly by email.",
      'We also use cookies to track the user on a session basis to give the user with content and information relating to his input and choice.',
      'We do not sell, trade, or rent the personal information to others. If the information if at all be sent, it may be done in the future with trustworthy third parties, but you can tell us not to do so - see the choice/opt out section below.',
      'Users may also choose not to receive future mailings, see the choice/opt-out section below.',
      "Demographic details and profile data is also collected at our site. We use this data to make the user's experience at our site, showing them content that we think they may be interested in, and displaying the contents for members according to their preferences.",
      'This information is also used to help us understand market characteristics and trends. This information may be shared with advertisers on an aggregate basis and will not include any personally identifying information.',
      "Also, We record our users IP addresses as well as use cookies to help identify the user as a unique member of our site. Furthermore, the user's IP address is used to help identify the user, track their site usage pattern, help us understand what attracts the user back to the site, which modules are most preferred by the members, and to gather broad demographic information.",
      'Please note, that this site contains links to other sites in terms of advertisements, co-branding promotions, alliances etc. Yourgoodtime.in is not responsible for the privacy practices or the content of those Web sites.',
      'By using this site you consent to the collection and use of this information by us for the reasons outlined above.',
    ],
    sections: [
      {
        heading: 'Choice to Opt Out of Mailing Lists',
        paragraphs: [
          'Our site provides users the opportunity to opt-out of receiving any sort of communications from us and our partners at the point where we request information about the user. This site gives users the option of not sending any promotional mails to them by allowing them to choose appropriately during the time of registration.',
        ],
      },
      {
        heading: 'Dispute Resolution Statement',
        paragraphs: [
          'At the best of our capacities and abilities, we do not forsee a cause or reason for dispute in the above mentioned privary policy. Still, if any dispute does arise the same can be amicably solved between both the parties by discussion and arbitration.',
          'At the maximum extent, the user is entitled to stop using all our services if our privacy policy is not acceptable to the user.',
          'Further for the existing users, we will ensure to take steps to remove all user related details from our records if it is specifically requested by the user. But there after the user will not be able to access or use any of our services.',
        ],
      },
      {
        heading: 'Contacting Us at Yourgoodtime.in',
        paragraphs: [
          'If you have any queries or concerns about this privacy statement, the practices of this site, or your dealings with us at yourgoodtime.in you can always contact our Customer Relations Manager for accurate and precise clarification of your questions.',
        ],
      },
    ],
  },
  refund: {
    title: 'Refund & Cancellation Policy',
    effectiveDate: '16 September 2026',
    intro: [
      'This Refund & Cancellation Policy applies to paid services offered through Your Good Time, including Future Prediction, Talk to Astrologer, and Temple Remedy for Your Horoscope.',
    ],
    sections: [
      {
        heading: '1. NO REFUND POLICY',
        paragraphs: [
          'Payments made for Your Good Time paid services are non-refundable once the payment has been verified and the requested service has been initiated or activated.',
          'Customers are therefore requested to review the service description and payment amount carefully before making payment.',
        ],
      },
      {
        heading: '2. FUTURE PREDICTION',
        paragraphs: [
          'Future Prediction consists of astrology-based prerecorded video content selected according to the user\'s horoscope/prediction data.',
          'Once payment has been verified and access to the Future Prediction service has been activated or the prediction has been generated for the user, no refund will be provided.',
          'The fact that a prediction or astrology interpretation does not match a user\'s expectation or preferred outcome is not a basis for a refund.',
        ],
      },
      {
        heading: '3. VIDEO PLAYBACK PROBLEMS',
        paragraphs: [
          'A technical playback problem does not automatically qualify for a refund.',
          'If an entitled prediction video cannot be accessed or played because of a technical problem, the user should report the issue to Your Good Time.',
          'We will make reasonable efforts to resolve the reported video-access/playback problem within 24 hours.',
          'The primary remedy for such a technical problem will be restoration or correction of access rather than a monetary refund.',
        ],
      },
      {
        heading: '4. TALK TO ASTROLOGER',
        paragraphs: [
          'Talk to Astrologer appointments are subject to availability and confirmation.',
          'After payment verification, the appointment date and time will be coordinated and confirmed with the customer.',
          'Once the consultation has been scheduled and the service process has been initiated, the payment is non-refundable.',
          'If Your Good Time needs to change the appointment because the astrologer is unavailable, an alternative available schedule will be coordinated with the customer.',
        ],
      },
      {
        heading: '5. TEMPLE REMEDY FOR YOUR HOROSCOPE',
        paragraphs: [
          'Once payment has been verified and horoscope assessment or preparation of the Temple Remedy service has commenced, the payment is non-refundable.',
          'The recommendation is astrology/spiritual guidance based on the user\'s horoscope. A user\'s decision not to visit the recommended temple or not to follow the suggested remedy after the service has been prepared does not create a right to a refund.',
        ],
      },
      {
        heading: '6. INCORRECT OR INCOMPLETE USER INFORMATION',
        paragraphs: [
          'Users are responsible for providing accurate birth and personal information required for the requested astrology service.',
          'No refund will be provided where a service has already been generated, prepared, or initiated using incorrect information originally supplied by the user.',
        ],
      },
      {
        heading: '7. DUPLICATE OR UNVERIFIED PAYMENTS',
        paragraphs: [
          'The no-refund policy does not prevent Your Good Time from correcting a genuine duplicate payment or a payment that was received but could not be matched to a valid service request.',
          'Any claimed duplicate payment or payment-verification discrepancy may be reviewed by the administration team using the payment transaction details and service records.',
        ],
      },
      {
        heading: '8. AGREEMENT',
        paragraphs: [
          'By making payment for a paid Your Good Time service, the customer acknowledges and agrees to this Refund & Cancellation Policy.',
        ],
      },
    ],
  },
  disclaimer: {
    title: 'Disclaimer',
    sections: [
      {
        heading: 'Confidentiality Disclaimer',
        paragraphs: [
          "Yourgoodtime assures complete confidentiality in regards to the details of members' information. We will only use information revealed in a member's REGISTRATION FORM data for the purpose of communicating predictive results directly to that member only.",
        ],
      },
      {
        heading: 'Email Disclaimer',
        paragraphs: [
          'Yourgoodtime.in does not sell or transfer email information to any entity or persons. Upon becoming a member of our site, we may send you newsletters and announcements from time to time. You may unsubscribe from this free publication at any time. Unsubscribe requests usually go into effect within 48 hours of the request.',
        ],
      },
      {
        heading: 'Product Liability Disclaimer',
        paragraphs: [
          'While yourgoodtime.in uses all best efforts to include accurate and up to date information, yourgoodtime.in makes no guarantees or representations regarding the accuracy or significance of any aspect of the astrological predictive advices given.',
          'Yourgoodtime.in cannot be held responsible for any interpretation, action, or use that may be made as a result of information given.',
          'In no event shall yourgoodtime.in, its employees, agents, suppliers, or contractors be liable for damages of any kind, including without limitation any compensatory, incidental, direct, indirect, special, punitive or consequential damages, loss of data, loss of use, loss of income or profit, damage to property, claims of third parties, or any other kind of losses arising out of or in connection to the yourgoodtime.in web site.',
          'The horoscope and predictive advice received by members should not be used as a substitution for the advice, programs, or treatment that would normally be received from a licensed professional, such as a lawyer, doctor, psychiatrist, or a financial adviser.',
          'Yourgoodtime.in will not be held responsible for any claims regarding any results negative, following undertaking of astrological remedies (gemstones, mantras, temple rituals...) by members.',
          'Yourgoodtime.in does not carry on or purport to carry on the treatment of any specific disease, disorder or ailment, nor does we promise to medically alleviate any specific physical condition or address any physical condition, or the lack thereof.',
          'By using this website, you acknowledge that you are not using the services for the cure or redressal of any specific disease, disorder, ailment, or physical condition.',
        ],
      },
      {
        heading: 'Copyright Disclaimer',
        paragraphs: [
          'All images, text, graphics, audios, videos, streaming videos are the property of yourgoodtime.in including the look and feel of the website.',
          'All of these are under copyright. None of the above may be resold, redistributed, or republished without prior written permission from us.',
        ],
      },
    ],
  },
}

function LegalDocumentPage({ type }) {
  const document = legalDocuments[type] || legalDocuments.privacy

  useEffect(() => {
    scrollPageToTop()
  }, [type])

  return (
    <main className="legal-document-page">
      <header className="home-hero-header legal-site-header" aria-label="Your Good Time header">
        <div className="home-hero-brand">
          <img src={ygtLogo} alt="YGT" className="home-hero-logo" />
          <div className="home-hero-brand-copy">
            <h1>Your Good Time</h1>
            <p>Your Journey Written in the Stars</p>
          </div>
        </div>

        <HeaderAuthActions />
      </header>

      <div className="legal-document-main">
        <article className="legal-document" aria-labelledby="legal-page-title">
          <header className="legal-document-title">
            <span>YourGoodTime Legal</span>
            <h1 id="legal-page-title">{document.title}</h1>
            {document.effectiveDate ? (
              <p className="legal-document-effective-date">Effective Date: {document.effectiveDate}</p>
            ) : null}
            <div className="legal-document-divider" aria-hidden="true" />
          </header>

          {document.intro ? (
            <section className="legal-document-section">
              {document.intro.map((paragraph) => (
                <p key={paragraph}>{paragraph}</p>
              ))}
            </section>
          ) : null}

          {document.sections?.map((section) => (
            <section className="legal-document-section" key={section.heading}>
              <h2>{section.heading}</h2>
              {section.paragraphs?.map((paragraph) => (
                <p key={paragraph}>{paragraph}</p>
              ))}
              {section.items?.length ? (
                <ul>
                  {section.items.map((item) => (
                    <li key={item}>{item}</li>
                  ))}
                </ul>
              ) : null}
              {section.closing?.map((paragraph) => (
                <p key={paragraph}>{paragraph}</p>
              ))}
            </section>
          ))}

          {document.placeholder ? (
            <section className="legal-document-section legal-document-placeholder">
              <p>{document.placeholder}</p>
            </section>
          ) : null}
        </article>
      </div>

      <AppFooter />
      <PrimaryNav />
    </main>
  )
}

export default LegalDocumentPage
